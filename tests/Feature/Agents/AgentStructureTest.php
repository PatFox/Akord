<?php

use App\Actions\Contracts\SignOffContract;
use App\Enums\ContractRole;
use App\Models\Contract;
use App\Models\Phase;
use App\Models\Step;
use Tests\Support\AgentClient;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step('Build the API');
    // Not a required approver, so it never blocks readiness or agreement.
    $this->agent = AgentClient::on($this, $this->s, required: false);
    $this->id = $this->s->contract->id;
});

/**
 * @return list<string> titles of the phase's active steps, in order
 */
function titlesIn(int $phaseId): array
{
    return Step::query()->with('currentRevision')->where('phase_id', $phaseId)->active()->orderBy('position')->get()
        ->map(fn (Step $step) => $step->currentRevision->title)->all();
}

/**
 * The JSON inside an MCP reply's <contract-data> block.
 *
 * @return array<string, mixed>
 */
function toolData(string $text): array
{
    preg_match('#<contract-data>(.*)</contract-data>#s', $text, $match);

    return json_decode($match[1] ?? 'null', true) ?? [];
}

function agreeContract(Scenario $s): void
{
    $s->approveAll(...Step::query()->whereRelation('phase', 'contract_id', $s->contract->id)->get()->all());
    $fingerprint = $s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($s->alpha, $s->contract, $fingerprint);
    app(SignOffContract::class)->handle($s->beta, $s->contract, $fingerprint);
}

test('an editor agent adds a phase and steps, moves and archives them over REST', function () {
    $phase = $this->agent->api('POST', "contracts/{$this->id}/phases", ['title' => 'Rollout'])->assertCreated()->json('data.id');
    $deploy = $this->agent->api('POST', "contracts/{$this->id}/phases/{$phase}/steps", ['title' => 'Deploy', 'description_md' => 'Ship it.'])
        ->assertCreated()
        ->assertJsonPath('data.revision.number', 1)
        ->assertJsonPath('data.revision.author.party', $this->agent->party->name)
        ->assertJsonPath('data.revision.author.user', null)
        ->json('data.id');
    $monitor = $this->agent->api('POST', "contracts/{$this->id}/phases/{$phase}/steps", ['title' => 'Monitor', 'description_md' => '', 'after_step_id' => 0])
        ->assertCreated()->json('data.id');

    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/move", ['phase_id' => $phase, 'after_step_id' => $monitor])->assertOk()
        ->assertJsonPath('data.phase_id', $phase)->assertJsonPath('data.position', 2);
    $this->agent->api('POST', "contracts/{$this->id}/steps/{$monitor}/move", [])->assertOk()->assertJsonPath('data.position', 3);
    $this->agent->api('POST', "contracts/{$this->id}/steps/{$deploy}/archive")->assertOk()->assertJsonPath('data.archived', true);

    expect(titlesIn($phase))->toBe(['Build the API', 'Monitor'])
        ->and(titlesIn($this->s->phase->id))->toBe([])
        ->and([$this->s->activityCount('phase.created'), $this->s->activityCount('step.created'), $this->s->activityCount('step.moved'), $this->s->activityCount('step.archived')])
        ->toBe([1, 3, 2, 1]);
});

test('the same operations work as MCP tools', function () {
    [$error, $text] = $this->agent->tool('create_phase', ['contract_id' => $this->id, 'title' => 'Rollout']);
    $phase = toolData($text)['id'];
    expect($error)->toBeFalse();

    [$error, $text] = $this->agent->tool('create_step', ['contract_id' => $this->id, 'phase_id' => $phase, 'title' => 'Deploy', 'description_md' => '']);
    $deploy = toolData($text)['id'];
    expect($error)->toBeFalse();

    expect($this->agent->tool('move_step', ['contract_id' => $this->id, 'step_id' => $this->step->id, 'phase_id' => $phase, 'after_step_id' => 0])[0])->toBeFalse()
        ->and($this->agent->tool('archive_step', ['contract_id' => $this->id, 'step_id' => $deploy])[0])->toBeFalse()
        ->and(titlesIn($phase))->toBe(['Build the API']);
});

test('structure changes are refused by role, token ability, scope and the lock', function () {
    $commenter = AgentClient::on($this, $this->s, ContractRole::Commenter, required: false);
    $noEdit = AgentClient::on($this, $this->s, required: false, abilities: ['read', 'comment']);
    $other = Contract::factory()->for($this->s->contract->project)->create();
    $otherPhase = Phase::factory()->for($other)->create(['position' => 1]);
    $phaseUri = "contracts/{$this->id}/phases/{$this->s->phase->id}/steps";

    $commenter->api('POST', "contracts/{$this->id}/phases", ['title' => 'X'])->assertForbidden();
    $noEdit->api('POST', $phaseUri, ['title' => 'X', 'description_md' => ''])->assertForbidden();
    $this->agent->api('POST', "contracts/{$this->id}/phases/{$otherPhase->id}/steps", ['title' => 'X', 'description_md' => ''])->assertNotFound();
    $this->agent->api('POST', "contracts/{$other->id}/phases", ['title' => 'X'])->assertNotFound();
    $this->agent->api('POST', $phaseUri, ['description_md' => ''])->assertUnprocessable();
    $this->agent->api('POST', $phaseUri, ['title' => 'X', 'description_md' => '', 'after_step_id' => 999999])->assertUnprocessable();
    [$error, $text] = $commenter->tool('archive_step', ['contract_id' => $this->id, 'step_id' => $this->step->id]);
    expect($error)->toBeTrue()->and($text)->toStartWith('403: ');

    agreeContract($this->s);
    $this->agent->api('POST', $phaseUri, ['title' => 'X', 'description_md' => ''])->assertStatus(423);
    $this->agent->api('POST', "contracts/{$this->id}/phases", ['title' => 'X'])->assertStatus(423);
    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/move", ['after_step_id' => 0])->assertStatus(423);
    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/archive")->assertStatus(423);

    expect(titlesIn($this->s->phase->id))->toBe(['Build the API'])
        ->and(Phase::query()->where('contract_id', $this->id)->count())->toBe(1);
});

test('adding a step cancels sign-offs; moving one keeps approvals and sign-offs', function () {
    $this->s->approveAll($this->step);
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $this->s->readiness()->fingerprint);
    $phase = $this->agent->api('POST', "contracts/{$this->id}/phases", ['title' => 'Later'])->json('data.id');

    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/move", ['phase_id' => $phase])->assertOk();
    expect($this->s->readiness()->isReady())->toBeTrue()
        ->and($this->s->readiness()->signedOffPartyIds)->toBe([$this->s->alpha->party->id]);

    $this->agent->api('POST', "contracts/{$this->id}/phases/{$phase}/steps", ['title' => 'New', 'description_md' => ''])->assertCreated();
    expect($this->s->readiness()->isReady())->toBeFalse()
        ->and($this->s->readiness()->signedOffPartyIds)->toBe([])
        ->and($this->s->activityCount('signoffs.invalidated'))->toBe(1);
});

test('archiving the last unapproved step makes the contract ready for sign-off', function () {
    $pending = $this->s->step('Not needed');
    $this->s->approveAll($this->step);
    expect($this->s->readiness()->isReady())->toBeFalse();

    $this->agent->api('POST', "contracts/{$this->id}/steps/{$pending->id}/archive")->assertOk();

    expect($this->s->readiness()->isReady())->toBeTrue();
});

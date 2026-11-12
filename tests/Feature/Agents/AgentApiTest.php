<?php

use App\Actions\Contracts\SignOffContract;
use App\Actions\Steps\ReviseStep;
use App\Models\Approval;
use App\Models\ContractSignoff;
use App\Support\Agents\UntrustedContent;
use Tests\Support\AgentClient;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step('Build the API', 'Endpoints for **contracts**.');
    $this->agent = AgentClient::on($this, $this->s);
    $this->id = $this->s->contract->id;
});

test('agents read contracts and steps with participant text under "content" and a notice', function () {
    $this->agent->api('GET', "contracts/{$this->id}")->assertOk()
        ->assertJsonPath('notice', UntrustedContent::NOTICE)
        ->assertJsonPath('data.your_party.role', 'editor')
        ->assertJsonPath('data.phases.0.steps.0.content.title', 'Build the API');

    $this->agent->api('GET', "contracts/{$this->id}/steps/{$this->step->id}")->assertOk()
        ->assertJsonPath('data.revision.number', 1)
        ->assertJsonPath('data.revision.content.description_md', 'Endpoints for **contracts**.')
        ->assertJsonPath('data.your_approval', 'none');
});

test('the Markdown export contains the steps and their approval state', function () {
    $response = $this->agent->api('GET', "contracts/{$this->id}?format=md")->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/markdown')
        ->and($response->getContent())->toContain('### 1. Build the API (revision 1)')->toContain('Endpoints for **contracts**.');
});

test('approving needs the reviewed revision; a content change since then is a conflict', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'Changed.');

    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/approval", ['revision' => 1])->assertConflict();
    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/approval", ['revision' => 2])->assertOk()
        ->assertJsonPath('data.approved_revision', 2);
    $this->agent->api('DELETE', "contracts/{$this->id}/steps/{$this->step->id}/approval")->assertOk()->assertJsonPath('data.withdrawn', 1);
    expect(Approval::query()->where('party_id', $this->agent->party->id)->active()->count())->toBe(0);
});

test('agents revise steps and comment, with stale edits refused', function () {
    $this->agent->api('PUT', "contracts/{$this->id}/steps/{$this->step->id}", [
        'base_revision' => 1, 'title' => 'Build the API', 'description_md' => 'Agent wording.', 'change_note' => 'Clarified',
    ])->assertOk()->assertJsonPath('data.number', 2)->assertJsonPath('data.author.party', $this->agent->party->name);

    $this->agent->api('PUT', "contracts/{$this->id}/steps/{$this->step->id}", [
        'base_revision' => 1, 'title' => 'Build the API', 'description_md' => 'Stale.',
    ])->assertConflict();

    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/comments", ['kind' => 'suggestion', 'body_md' => 'Add rate limits.'])
        ->assertCreated()->assertJsonPath('data.content.body_md', 'Add rate limits.')->assertJsonPath('data.author.user', null);
});

test('the diff endpoint returns a unified diff of two revisions', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'Endpoints for **contracts** and steps.');

    $diff = $this->agent->api('GET', "contracts/{$this->id}/steps/{$this->step->id}/diff?from=1&to=2")->assertOk()->json('data.content.unified_diff');

    expect($diff)->toContain('-Endpoints for **contracts**.')->toContain('+Endpoints for **contracts** and steps.');
});

test('sign-off over the API: not ready 422, stale 409, then agreed and locked 423', function () {
    $status = fn () => $this->agent->api('GET', "contracts/{$this->id}/status")->json('data');

    $this->agent->api('POST', "contracts/{$this->id}/signoffs", ['fingerprint' => $status()['fingerprint']])->assertUnprocessable();

    $this->s->approveAll($this->step);
    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/approval", ['revision' => 1])->assertOk();
    $this->agent->api('POST', "contracts/{$this->id}/signoffs", ['fingerprint' => str_repeat('0', 64)])->assertConflict();

    $fingerprint = $status()['fingerprint'];
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);
    $this->agent->api('POST', "contracts/{$this->id}/signoffs", ['fingerprint' => $fingerprint])->assertCreated()->assertJsonPath('data.agreed', true);

    $this->agent->api('DELETE', "contracts/{$this->id}/signoffs/mine")->assertStatus(423);
    expect($status()['state'])->toBe('agreed');
});

test('D10: with human sign-off required, an agent sign-off is forbidden', function () {
    $this->s->approveAll($this->step);
    $this->agent->api('POST', "contracts/{$this->id}/steps/{$this->step->id}/approval", ['revision' => 1]);
    $this->s->contract->update(['require_human_signoff' => true]);
    $fingerprint = $this->agent->api('GET', "contracts/{$this->id}/status")->json('data.fingerprint');

    $this->agent->api('POST', "contracts/{$this->id}/signoffs", ['fingerprint' => $fingerprint])->assertForbidden();
    expect(ContractSignoff::query()->count())->toBe(0);
});

test('the activity feed pages forward from a cursor in commit order', function () {
    $first = $this->agent->api('GET', 'activity')->assertOk()->json('data');
    expect(collect($first['events'])->pluck('type')->all())->toContain('step.created');

    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'New text.');
    $next = $this->agent->api('GET', 'activity?since='.$first['next_cursor'])->assertOk()->json('data');

    expect(collect($next['events'])->pluck('type')->all())->toBe(['step.revised'])
        ->and($next['next_cursor'])->toBeGreaterThan($first['next_cursor'])
        ->and($next['events'][0]['content']['description'])->toContain('edited “Build the API”');
});

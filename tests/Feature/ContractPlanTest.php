<?php

use App\Actions\Phases\CreatePhase;
use App\Actions\Steps\ArchiveStep;
use App\Models\User;
use App\Support\ContractPlan;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->s->contract->update(['title' => "Payments\nAPI"]);
});

function plan(Scenario $s): string
{
    return ContractPlan::markdown($s->contract->fresh(), $s->readiness());
}

test('the plan numbers phases and steps in order and leaves out archived steps', function () {
    $this->s->step('Endpoints', 'List them.');
    $dropped = $this->s->step('Dropped', 'Gone.');
    app(ArchiveStep::class)->handle($this->s->alpha, $dropped);
    $build = app(CreatePhase::class)->handle($this->s->alpha, $this->s->contract, 'Build');
    $this->s->step('Webhooks', 'Signed.', $build);
    app(CreatePhase::class)->handle($this->s->alpha, $this->s->contract, 'Later');

    $plan = plan($this->s);

    expect($plan)->toStartWith("# Payments API\n")
        ->toContain('Version 1 · Draft')
        ->toContain('Fingerprint: `'.$this->s->readiness()->fingerprint.'`')
        ->not->toContain('Dropped')
        ->and(preg_match_all('/^#{2,3} .*$/m', $plan, $m))->toBe(5)
        ->and($m[0])->toBe(['## 1. Phase one', '### 1.1 Endpoints', '## 2. Build', '### 2.1 Webhooks', '## 3. Later'])
        ->and($plan)->toContain("## 3. Later\n\n_No steps._");
});

test('headings in a step\'s text sit under the step, except inside code blocks', function () {
    $this->s->step('Endpoints', "# Overview\n\nText.\n\n```md\n# not a heading\n```\n\n#### Deep");

    expect(plan($this->s))
        ->toContain("\n#### Overview\n")
        ->toContain("\n# not a heading\n")
        ->toContain("\n###### Deep");
});

test('the Plan tab shows the document and a download; the download is a named .md file for people who can see the contract', function () {
    $this->s->step('Endpoints', 'List **them**.');
    $this->actingAs($this->s->alpha->user);

    Livewire::withQueryParams(['tab' => 'plan'])->test('pages::contracts.show', ['contract' => $this->s->contract])
        ->assertSeeHtml('data-test="plan"')->assertSeeHtml('<strong>them</strong>')->assertSee('Download .md')
        ->assertDontSeeHtml('data-test="steps-panel"')
        ->call('$set', 'tab', 'steps')->assertSeeHtml('data-test="steps-panel"');

    $this->get(route('contracts.plan', $this->s->contract))->assertOk()
        ->assertDownload('payments-api-v1.md')
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');

    $this->actingAs(User::factory()->create())->get(route('contracts.plan', $this->s->contract))->assertNotFound();
});

<?php

use App\Actions\Approvals\ApproveStep;
use App\Actions\Contracts\SetRequiredApprover;
use App\Actions\Steps\CreateStep;
use App\Actions\Steps\ReviseStep;
use App\Enums\ContractRole;
use App\Events\ActivityRecorded;
use App\Models\ActivityEvent;
use App\Models\ContractSignoff;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step('Build the API', 'Original text.');
});

/**
 * Deliver every activity the contract recorded after $afterPosition to a page, one broadcast each, as Echo does.
 */
function deliverActivitySince(object $page, Scenario $s, int $afterPosition): object
{
    ActivityEvent::query()->where('contract_id', $s->contract->id)->where('position', '>', $afterPosition)->orderBy('position')->get()
        ->each(fn (ActivityEvent $a) => $page->dispatch('echo-private:contract.'.$s->contract->id.',.activity', ['id' => $a->id, 'type' => $a->type]));

    return $page;
}

function lastActivityPosition(Scenario $s): int
{
    return (int) ActivityEvent::query()->where('contract_id', $s->contract->id)->max('position');
}

test('only people who can see the contract may subscribe to its channel', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => '1',
    ]);
    require base_path('routes/channels.php');

    $outsider = User::factory()->create();
    Party::factory()->create()->users()->attach($outsider);
    $subscribe = fn (User $user) => $this->actingAs($user)->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-contract.'.$this->s->contract->id,
    ])->status();

    expect($subscribe($this->s->beta->user))->toBe(200)
        ->and($subscribe($this->s->owner))->toBe(200)
        ->and($subscribe($outsider))->toBe(403);
});

test('each change is broadcast on the contract channel with ids only, no contract text', function () {
    Event::fake([ActivityRecorded::class]);

    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Secret title', 'Secret text.');

    Event::assertDispatched(ActivityRecorded::class, function (ActivityRecorded $event) {
        $payload = $event->broadcastWith();

        return $event->broadcastOn()[0]->name === 'private-contract.'.$this->s->contract->id
            && $event->broadcastAs() === 'activity'
            && array_keys($payload) === ['id', 'type', 'contract_id', 'subject_type', 'subject_id']
            && $payload['type'] === 'step.revised'
            && ! str_contains(json_encode($payload), 'Secret');
    });
});

test('a content change while reviewing holds sign-off until the person acknowledges it', function () {
    $this->s->approveAll($this->step);
    $otherEditor = $this->s->party(ContractRole::Editor);
    $this->actingAs($this->s->alpha->user);
    $page = Livewire::test('pages::contracts.show', ['contract' => $this->s->contract]);
    $opened = lastActivityPosition($this->s);

    $added = app(CreateStep::class)->handle($otherEditor, $this->s->phase, 'Late step', 'Added meanwhile.');
    deliverActivitySince($page, $this->s, $opened)
        ->assertSee('Contract changed; review again')
        ->assertSee('added step “Late step”');

    $this->s->approveAll($added);
    $page->call('signOff');
    expect(ContractSignoff::query()->count())->toBe(0);

    $page->call('reviewAgain')->assertSee('Sign off contract')->call('signOff');
    expect(ContractSignoff::query()->where('party_id', $this->s->alpha->party->id)->count())->toBe(1);
});

test('an approval elsewhere updates the page without holding sign-off', function () {
    $this->s->approve($this->s->alpha, $this->step);
    $this->actingAs($this->s->alpha->user);
    $page = Livewire::test('pages::contracts.show', ['contract' => $this->s->contract])->assertSee('Draft');
    $opened = lastActivityPosition($this->s);

    app(ApproveStep::class)->handle($this->s->beta, $this->step, 1);
    deliverActivitySince($page, $this->s, $opened)
        ->assertSee('Ready for sign-off')
        ->assertDontSee('Contract changed; review again')
        ->call('signOff');

    expect(ContractSignoff::query()->count())->toBe(1);
});

test('the contract page passes an activity on to the step it concerns, or to every step', function () {
    $this->actingAs($this->s->alpha->user);
    $page = Livewire::test('pages::contracts.show', ['contract' => $this->s->contract]);
    $opened = lastActivityPosition($this->s);

    app(ApproveStep::class)->handle($this->s->beta, $this->step, 1);
    deliverActivitySince($page, $this->s, $opened)->assertDispatched('step-refresh.'.$this->step->id)->assertNotDispatched('steps-refresh');

    $opened = lastActivityPosition($this->s);
    app(SetRequiredApprover::class)->handle($this->s->ownerActor(), $this->s->contract, $this->s->beta->party, false);
    deliverActivitySince($page, $this->s, $opened)->assertDispatched('steps-refresh');
});

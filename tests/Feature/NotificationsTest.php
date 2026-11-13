<?php

use App\Actions\Comments\AddComment;
use App\Actions\Contracts\InviteToContract;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use App\Mail\InvitationMail;
use App\Models\ActivityEvent;
use App\Models\Step;
use App\Models\User;
use App\Notifications\ContractActivity;
use App\Support\Actor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    // A third, non-required party: it takes part in the contract but never approves.
    $this->gamma = $this->s->party(ContractRole::Commenter);
});

/**
 * @return list<int> ids of users who got a ContractActivity for this activity type
 */
function notifiedFor(string $type, ?bool $approvalCancelled = null): array
{
    return array_values(User::query()->orderBy('id')->get()
        ->filter(fn (User $user) => Notification::sent($user, ContractActivity::class)
            ->contains(fn (ContractActivity $n) => $n->activity->type === $type
                && ($approvalCancelled === null || $n->approvalCancelled === $approvalCancelled)))
        ->modelKeys());
}

function revise(Actor $actor, Step $step, string $title, string $description): void
{
    app(ReviseStep::class)->handle($actor, $step, $step->fresh()->currentRevision->number, $title, $description);
}

test('a comment notifies the other people on the contract, not the author, unattached owners or outsiders', function () {
    Notification::fake();
    $step = $this->s->step();
    User::factory()->create();

    app(AddComment::class)->handle($this->s->beta, $step, CommentKind::Question, 'Which endpoints?');

    expect(notifiedFor('comment.added'))->toBe([$this->s->alpha->user->id, $this->gamma->user->id]);
    Notification::assertSentTo($this->s->alpha->user, ContractActivity::class, fn (ContractActivity $n, array $channels) => $channels === ['database', 'mail']
        && $n->toArray($this->s->alpha->user)['url'] === route('steps.show', [$this->s->contract, $step]));
});

test('a content edit tells the parties whose approval it cancelled; a spacing-only edit tells nobody', function () {
    Notification::fake();
    $step = $this->s->step('Build the API', 'Endpoints for contracts.');
    $this->s->approve($this->s->beta, $step);

    revise($this->s->alpha, $step, 'Build the API', "Endpoints  for\ncontracts.");
    expect(notifiedFor('step.revised'))->toBe([]);

    revise($this->s->alpha, $step, 'Build the REST API', 'Endpoints for contracts.');

    expect(notifiedFor('step.revised', approvalCancelled: true))->toBe([$this->s->beta->user->id])
        ->and(notifiedFor('step.revised', approvalCancelled: false))->toBe([$this->gamma->user->id])
        ->and(ActivityEvent::query()->where('type', 'step.revised')->latest('id')->first()->payload['cancelled_party_ids'])->toBe([$this->s->beta->party->id]);

    Notification::assertSentTo($this->s->beta->user, ContractActivity::class, fn (ContractActivity $n) => str_contains((string) $n->toArray($this->s->beta->user)['detail'], 'approval of this step was cancelled'));
});

test('ready for sign-off goes to required parties only; agreement goes to everyone but the final signer', function () {
    Notification::fake();
    $step = $this->s->step();
    $this->s->approveAll($step);

    // Beta's approval completed the set, so beta (the actor) is not told.
    expect(notifiedFor('contract.ready'))->toBe([$this->s->alpha->user->id]);

    $fingerprint = $this->s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);

    expect(notifiedFor('contract.agreed'))->toBe([$this->s->alpha->user->id, $this->gamma->user->id]);
});

test('a change that cancels sign-offs tells the parties that had signed off', function () {
    Notification::fake();
    $step = $this->s->step();
    $this->s->approveAll($step);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $this->s->readiness()->fingerprint);

    revise($this->s->alpha, $step, 'Build the REST API', 'Endpoints for contracts.');

    expect(notifiedFor('signoffs.invalidated'))->toBe([$this->s->beta->user->id]);
});

test('an accepted invitation tells the owner who sent it', function () {
    Notification::fake();
    Mail::fake();
    app(InviteToContract::class)->handle($this->s->ownerActor(), $this->s->contract, $this->gamma->party, 'carol@example.com');
    $token = null;
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    app(AcceptInvitation::class)->handle(User::factory()->create(['email' => 'carol@example.com']), $token);

    expect(notifiedFor('invitation.accepted'))->toBe([$this->s->owner->id]);
});

test('people without a verified email are told in the app only, and nobody who lost access is told', function () {
    $step = $this->s->step();
    $this->gamma->user->forceFill(['email_verified_at' => null])->save();
    $comment = fn () => app(AddComment::class)->handle($this->s->beta, $step, CommentKind::General, 'Looks fine.');

    Notification::fake();
    $comment();
    Notification::assertSentTo($this->gamma->user, ContractActivity::class, fn ($n, array $channels) => $channels === ['database']);

    $activity = ActivityEvent::query()->where('type', 'comment.added')->latest('id')->firstOrFail();
    $this->gamma->party->users()->detach($this->gamma->user);

    expect((new ContractActivity($activity))->shouldSend($this->gamma->user, 'database'))->toBeFalse()
        ->and((new ContractActivity($activity))->shouldSend($this->s->alpha->user, 'database'))->toBeTrue();
});

test('emails escape participants’ text so a title cannot add a link', function () {
    Notification::fake();
    $step = $this->s->step('[Click here](https://evil.example)', 'Body');
    app(AddComment::class)->handle($this->s->beta, $step, CommentKind::General, 'Hi');

    $activity = ActivityEvent::query()->where('type', 'comment.added')->firstOrFail();
    $html = (string) (new ContractActivity($activity))->toMail($this->s->alpha->user)->render();

    expect($html)->not->toContain('href="https://evil.example"')
        ->and($html)->toContain('Click here')
        ->and($html)->toContain(route('steps.show', [$this->s->contract, $step]));
});

test('the notifications page lists, opens and marks notifications read', function () {
    $step = $this->s->step();
    app(AddComment::class)->handle($this->s->beta, $step, CommentKind::Question, 'Which endpoints?');
    $alice = $this->s->alpha->user;
    $notification = $alice->notifications()->sole();

    $page = $this->actingAs($alice)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('commented on “Build the API”');
    // The sidebar link carries the unread count.
    expect(preg_match('/data-test="notifications-link".*?<\/a>/s', $page->getContent(), $link))->toBe(1)
        ->and(trim((string) preg_replace('/\s+/', ' ', strip_tags($link[0]))))->toEndWith('Notifications 1');

    Livewire::actingAs($alice)->test('pages::notifications.index')
        ->call('open', $notification->id)
        ->assertRedirect(route('steps.show', [$this->s->contract, $step]));

    expect($notification->fresh()->read_at)->not->toBeNull();

    // Someone else's notification id is not found.
    Livewire::actingAs($this->gamma->user)->test('pages::notifications.index')
        ->call('open', $notification->id)
        ->assertNotFound();

    app(AddComment::class)->handle($this->s->beta, $step, CommentKind::General, 'Second');
    Livewire::actingAs($alice)->test('pages::notifications.index')->call('markAllRead');

    expect($alice->unreadNotifications()->count())->toBe(0);
});

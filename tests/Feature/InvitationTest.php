<?php

use App\Actions\Contracts\CancelInvitation;
use App\Actions\Contracts\InviteToContract;
use App\Actions\Contracts\ResendInvitation;
use App\Actions\Invitations\AcceptInvitation;
use App\Enums\ContractRole;
use App\Mail\InvitationMail;
use App\Models\Contract;
use App\Models\Invitation;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\Scenario;

beforeEach(function () {
    Mail::fake();
    $this->s = Scenario::make();
    $this->party = $this->s->beta->party;
});

/**
 * Invite as the owner; returns the raw token from the email that was sent.
 */
function invite(Scenario $s, Party $party, string $email): string
{
    app(InviteToContract::class)->handle($s->ownerActor(), $s->contract, $party, $email);

    $token = null;
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$token) {
        $token = $mail->token;

        return true;
    });

    return $token;
}

function accept(User $user, string $token): int
{
    return status(fn () => app(AcceptInvitation::class)->handle($user, $token));
}

test('a valid link adds the invited person to the party, and so to the contract', function () {
    $token = invite($this->s, $this->party, ' Carol@Example.com ');
    $carol = User::factory()->create(['email' => 'CAROL@example.com']);

    expect(accept($carol, $token))->toBe(200)
        ->and($this->party->users()->whereKey($carol->id)->exists())->toBeTrue()
        ->and(Contract::query()->visibleTo($carol)->pluck('id')->all())->toBe([$this->s->contract->id])
        ->and(Invitation::query()->sole()->only(['email', 'accepted_user_id']))->toBe(['email' => 'carol@example.com', 'accepted_user_id' => $carol->id])
        ->and($this->s->activityCount('invitation.accepted'))->toBe(1);
});

test('only the token hash is stored, never the token', function () {
    $token = invite($this->s, $this->party, 'carol@example.com');

    expect(Invitation::query()->sole()->token_hash)->toBe(hash('sha256', $token))
        ->and(DB::table('invitations')->where('token_hash', $token)->exists())->toBeFalse();
});

test('used, expired and cancelled links are rejected, and an unknown link is not found', function () {
    [$carol, $dave, $erin] = collect(['carol', 'dave', 'erin'])->map(fn ($name) => User::factory()->create(['email' => "{$name}@example.com"]))->all();
    $used = invite($this->s, $this->party, 'carol@example.com');
    $expired = invite($this->s, $this->party, 'dave@example.com');
    $cancelled = invite($this->s, $this->party, 'erin@example.com');

    accept($carol, $used);
    app(CancelInvitation::class)->handle($this->s->ownerActor(), Invitation::query()->where('email', 'erin@example.com')->sole());
    $this->travel(InviteToContract::VALID_FOR_DAYS + 1)->days();

    expect(accept($carol, $used))->toBe(410)
        ->and(accept($dave, $expired))->toBe(410)
        ->and(accept($erin, $cancelled))->toBe(410)
        ->and(accept($carol, 'not-a-real-token'))->toBe(404)
        ->and($this->party->users()->whereIn('users.id', [$dave->id, $erin->id])->exists())->toBeFalse();
});

test('a link opened by an account with a different email is refused and changes nothing', function () {
    $token = invite($this->s, $this->party, 'carol@example.com');
    $mallory = User::factory()->create(['email' => 'mallory@example.com']);

    expect(accept($mallory, $token))->toBe(403)
        ->and($this->party->users()->whereKey($mallory->id)->exists())->toBeFalse()
        ->and(Invitation::query()->sole()->status())->toBe('pending');
});

test('resending issues a new link and the old one stops working', function () {
    $old = invite($this->s, $this->party, 'carol@example.com');
    app(ResendInvitation::class)->handle($this->s->ownerActor(), Invitation::query()->sole());
    $new = null;
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use (&$new) {
        $new = $mail->token;

        return true;
    });
    $carol = User::factory()->create(['email' => 'carol@example.com']);

    expect($new)->not->toBe($old)
        ->and(accept($carol, $old))->toBe(404)
        ->and(accept($carol, $new))->toBe(200);
});

test('only owners invite, and only into attached human parties without that member or a pending invite', function () {
    $agent = $this->s->party(ContractRole::Commenter, agent: true)->party;
    $unattached = Party::factory()->for($this->s->organisation)->create();
    $member = $this->s->alpha->user;
    $attempt = fn ($actor, Party $party, string $email) => status(fn () => app(InviteToContract::class)->handle($actor, $this->s->contract, $party, $email));

    expect($attempt($this->s->alpha, $this->party, 'x@example.com'))->toBe(403)
        ->and($attempt($this->s->ownerActor(), $agent, 'x@example.com'))->toBe(422)
        ->and($attempt($this->s->ownerActor(), $unattached, 'x@example.com'))->toBe(422)
        ->and($attempt($this->s->ownerActor(), $this->s->alpha->party, strtoupper($member->email)))->toBe(422)
        ->and($attempt($this->s->ownerActor(), $this->party, 'x@example.com'))->toBe(200)
        ->and($attempt($this->s->ownerActor(), $this->party, 'X@example.com'))->toBe(422)
        ->and(Invitation::query()->count())->toBe(1);
});

test('a guest registers from the invitation page and comes back to accept it', function () {
    $token = invite($this->s, $this->party, 'carol@example.com');

    $this->get(route('invitations.show', ['token' => $token]))->assertOk()->assertSee($this->s->contract->title)->assertSee('Create an account');
    $this->post(route('register.store'), [
        'name' => 'Carol', 'email' => 'Carol@Example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect(route('invitations.show', ['token' => $token]));

    Livewire::test('pages::invitations.show', ['token' => $token])
        ->call('accept')
        ->assertRedirect(route('contracts.show', $this->s->contract));
    expect($this->party->users()->where('users.email', 'carol@example.com')->exists())->toBeTrue();
});

test('the invitation page warns a signed-in person using a different account', function () {
    $token = invite($this->s, $this->party, 'carol@example.com');

    $this->actingAs(User::factory()->create(['email' => 'mallory@example.com']))
        ->get(route('invitations.show', ['token' => $token]))
        ->assertOk()->assertSee('this invitation is for carol@example.com')->assertDontSee('Accept invitation');
});

test('if the email cannot be sent, the invitation is kept so the owner can resend it', function () {
    Mail::shouldReceive('to')->andThrow(new TransportException('Connection refused'));
    $this->actingAs($this->s->owner);

    Livewire::test('pages::contracts.settings', ['contract' => $this->s->contract])
        ->set('inviteEmail', 'carol@example.com')->set('invitePartyId', $this->party->id)->call('invite')
        ->assertHasNoErrors()
        ->assertSee('carol@example.com');

    expect(Invitation::query()->sole()->status())->toBe('pending');
});

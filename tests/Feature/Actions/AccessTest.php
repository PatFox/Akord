<?php

use App\Actions\Approvals\ApproveStep;
use App\Actions\Comments\AddComment;
use App\Actions\Contracts\AttachParty;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use App\Models\Party;
use App\Models\User;
use App\Support\Actor;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step();
});

function can(object $t, Actor $actor): array
{
    return [
        'edit' => status(fn () => app(ReviseStep::class)->handle($actor, $t->step, $t->step->fresh()->currentRevision->number, 'T '.uniqid(), 'D')),
        'comment' => status(fn () => app(AddComment::class)->handle($actor, $t->step, CommentKind::General, 'Hi')),
        'approve' => status(fn () => app(ApproveStep::class)->handle($actor, $t->step, $t->step->fresh()->currentRevision->number)),
    ];
}

test('roles decide what a party can do', function () {
    expect(can($this, $this->s->party(ContractRole::Viewer)))->toBe(['edit' => 403, 'comment' => 403, 'approve' => 403])
        ->and(can($this, $this->s->party(ContractRole::Commenter)))->toBe(['edit' => 403, 'comment' => 200, 'approve' => 200])
        ->and(can($this, $this->s->party(ContractRole::Editor)))->toBe(['edit' => 200, 'comment' => 200, 'approve' => 200]);
});

test('an agent token without an ability is forbidden from that action only', function () {
    $agent = $this->s->party(ContractRole::Editor, agent: true, abilities: ['read', 'edit', 'comment']);

    expect(can($this, $agent))->toBe(['edit' => 200, 'comment' => 200, 'approve' => 403]);
});

test('people and agents outside the contract get not-found, even from another organisation', function () {
    $otherOrgUser = User::factory()->create();
    $otherOrgParty = Party::factory()->create();
    $otherOrgParty->users()->attach($otherOrgUser);
    $unattachedAgent = Party::factory()->for($this->s->organisation)->agent()->create();

    expect(can($this, Actor::user($otherOrgUser, $otherOrgParty)))->toBe(['edit' => 404, 'comment' => 404, 'approve' => 404])
        ->and(can($this, Actor::agent($unattachedAgent)))->toBe(['edit' => 404, 'comment' => 404, 'approve' => 404]);
});

test('acting for a party you do not belong to is forbidden', function () {
    $intruder = User::factory()->create();
    $this->s->beta->party->users()->attach($intruder);

    expect(can($this, Actor::user($intruder, $this->s->alpha->party)))->toBe(['edit' => 403, 'comment' => 403, 'approve' => 403]);
});

test('owners can edit and comment as their own party but approvals stay with attached parties', function () {
    $ownParty = Party::factory()->for($this->s->organisation)->create();
    $ownParty->users()->attach($this->s->owner);

    expect(can($this, $this->s->ownerActor($ownParty)))->toBe(['edit' => 200, 'comment' => 200, 'approve' => 403])
        ->and(status(fn () => app(ReviseStep::class)->handle($this->s->ownerActor(), $this->step, 1, 'T', 'D')))->toBe(422);
});

test('only owners attach parties, and only parties of the contract organisation', function () {
    $local = Party::factory()->for($this->s->organisation)->create();
    $foreign = Party::factory()->create();

    expect(status(fn () => app(AttachParty::class)->handle($this->s->alpha, $this->s->contract, $local, ContractRole::Viewer)))->toBe(403)
        ->and(status(fn () => app(AttachParty::class)->handle($this->s->ownerActor(), $this->s->contract, $foreign, ContractRole::Viewer)))->toBe(404)
        ->and(status(fn () => app(AttachParty::class)->handle($this->s->ownerActor(), $this->s->contract, $local, ContractRole::Viewer)))->toBe(200);
});

<?php

use App\Models\Approval;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Models\Party;
use App\Models\Step;
use App\Models\StepRevision;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('content hash ignores spacing-only differences but not content changes', function () {
    $hash = StepRevision::contentHash('Build the API', "First line\nSecond   line");

    expect(StepRevision::contentHash('  Build  the API ', "First line\r\n\r\n  Second line\n"))->toBe($hash)
        ->and(StepRevision::contentHash('Build the APIs', "First line\nSecond line"))->not->toBe($hash)
        ->and(StepRevision::contentHash('Build the API', 'First line Second line.'))->not->toBe($hash)
        ->and(StepRevision::contentHash('Build the API First', 'line Second line'))->not->toBe($hash);
});

test('a created revision stores the content hash of its title and description', function () {
    $revision = StepRevision::factory()->create(['title' => 'T', 'description_md' => "a\n\nb"]);

    expect($revision->fresh()->content_hash)->toBe(StepRevision::contentHash('T', 'a b'));
});

test('step revisions are append-only', function () {
    $revision = Step::factory()->create()->currentRevision;

    expect(fn () => $revision->update(['title' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $revision->delete())->toThrow(LogicException::class);
});

test('revision numbers are unique per step', function () {
    $step = Step::factory()->create();

    StepRevision::factory()->for($step)->create(['number' => 1]);
})->throws(UniqueConstraintViolationException::class);

test("a step's current revision must be one of its own revisions", function () {
    $step = Step::factory()->create();
    $other = Step::factory()->create();

    $next = StepRevision::factory()->for($step)->create();
    $step->update(['current_revision_id' => $next->id]);
    expect($step->fresh()->current_revision_id)->toBe($next->id);

    $step->update(['current_revision_id' => $other->current_revision_id]);
})->throws(QueryException::class);

test('a comment must reference a revision of the same step', function () {
    $step = Step::factory()->create();
    $other = Step::factory()->create();

    Comment::factory()->for($step)->create(['step_revision_id' => $other->current_revision_id]);
})->throws(QueryException::class);

test('a reply must belong to the same step as its parent comment', function () {
    $parent = Comment::factory()->create();

    $reply = Comment::factory()->for($parent->step)->create(['parent_id' => $parent->id]);
    expect($parent->replies()->pluck('id')->all())->toBe([$reply->id]);

    Comment::factory()->create(['parent_id' => $parent->id]);
})->throws(QueryException::class);

test('a party has at most one active approval per step revision', function () {
    $step = Step::factory()->create();
    $party = Party::factory()->create();

    Approval::factory()->for($step)->for($party)->create();
    Approval::factory()->for($step)->for($party)->create();
})->throws(UniqueConstraintViolationException::class);

test('a withdrawn approval does not block approving the same revision again', function () {
    $step = Step::factory()->create();
    $party = Party::factory()->create();

    Approval::factory()->for($step)->for($party)->create(['withdrawn_at' => now()]);
    Approval::factory()->for($step)->for($party)->create();

    expect($step->approvals()->active()->count())->toBe(1);
});

test('a party has at most one active sign-off per contract fingerprint', function () {
    $contract = Contract::factory()->create();
    $party = Party::factory()->create();
    $fingerprint = hash('sha256', 'state');

    ContractSignoff::factory()->for($contract)->for($party)->create(['fingerprint' => $fingerprint, 'withdrawn_at' => now()]);
    ContractSignoff::factory()->for($contract)->for($party)->create(['fingerprint' => $fingerprint]);

    expect(fn () => ContractSignoff::factory()->for($contract)->for($party)->create(['fingerprint' => $fingerprint]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a contract version can be continued only once', function () {
    $v1 = Contract::factory()->create();

    Contract::factory()->for($v1->project)->create(['version' => 2, 'previous_version_id' => $v1->id]);
    Contract::factory()->for($v1->project)->create(['version' => 2, 'previous_version_id' => $v1->id]);
})->throws(UniqueConstraintViolationException::class);

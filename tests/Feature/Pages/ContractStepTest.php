<?php

use App\Actions\Comments\AddComment;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use App\Models\ActivityEvent;
use App\Models\Comment;
use App\Models\StepRevision;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step('Build the API', 'Original text.');
});

function stepItem(object $t, $actor)
{
    test()->actingAs($actor->user);

    return Livewire::test('pages::contracts.step', ['contract' => $t->s->contract, 'step' => $t->step]);
}

test('saving over someone else\'s edit shows a conflict and keeps the draft; saving again applies it on top', function () {
    $otherEditor = $this->s->party(ContractRole::Editor);
    $mine = stepItem($this, $this->s->alpha)->call('edit')->set('description', 'My wording.');
    stepItem($this, $otherEditor)->call('edit')->set('description', 'Their wording.')->call('save')->assertDispatched('step-updated');

    $mine->call('save')
        ->assertSee('This step changed while you were editing')
        ->assertSet('description', 'My wording.')
        ->assertSet('editing', true);
    expect(StepRevision::query()->where('step_id', $this->step->id)->count())->toBe(2);

    $mine->call('rebase')->call('save')->assertSet('editing', false);
    expect($this->step->fresh()?->currentRevision?->only(['number', 'description_md']))
        ->toBe(['number' => 3, 'description_md' => 'My wording.']);
});

test('an editor sees the conflict as soon as the contract page reports someone else\'s save', function () {
    $otherEditor = $this->s->party(ContractRole::Editor);
    $page = stepItem($this, $this->s->alpha)->call('edit')->set('description', 'My draft.');

    app(ReviseStep::class)->handle($otherEditor, $this->step, 1, 'Build the API', 'Their change.');
    $page->dispatch('step-refresh.'.$this->step->id)
        ->assertSee('This step changed while you were editing')
        ->assertSet('description', 'My draft.');
});

test('a required approver approves and withdraws with their own tick; other ticks are not clickable', function () {
    $page = stepItem($this, $this->s->beta)->assertSeeHtml('data-test="my-tick"');

    $page->call('approve', 1)->assertDispatched('step-updated');
    expect($this->s->readiness()->outstanding)->toBe([['step_id' => $this->step->id, 'party_id' => $this->s->alpha->party->id]]);

    $page->call('withdrawApproval');
    expect($this->s->readiness()->outstanding)->toHaveCount(2);

    // Approving what is no longer on screen is refused.
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'Changed.');
    $page->call('approve', 1);
    expect($this->s->readiness()->outstanding)->toHaveCount(2);

    stepItem($this, $this->s->party(ContractRole::Viewer))->assertDontSeeHtml('data-test="my-tick"');
    stepItem($this, $this->s->party(ContractRole::Commenter))->assertDontSeeHtml('data-test="my-tick"');
});

test('a party whose approval was cancelled by an edit is told so', function () {
    $this->s->approve($this->s->beta, $this->step);
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'Changed scope.');

    stepItem($this, $this->s->beta)->assertSee('The step changed after your party approved it')->assertSee('approve revision 2');
});

test('descriptions and comments render Markdown on the contract page without raw HTML or script links', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', "**Bold** <script>alert('x')</script> [click](javascript:alert(1)) <img src=x onerror=alert(1)>");
    app(AddComment::class)->handle($this->s->beta, $this->step, CommentKind::Question, '<iframe src="https://evil.test"></iframe> fine?');

    $html = $this->actingAs($this->s->beta->user)->get(route('contracts.show', $this->s->contract))->assertOk()->getContent();

    expect($html)->toContain('<strong>Bold</strong>')
        ->not->toContain("<script>alert('x')")
        ->not->toContain('href="javascript:')
        ->not->toContain('<img src=x')
        ->not->toContain('<iframe');
});

test('the history diff highlights changed words and escapes revision text', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the <b>API</b>', 'Original text, extended.');

    $page = stepItem($this, $this->s->alpha)->call('toggleHistory')->assertSee('Revision 2');
    $diff = $page->instance()->diffHtml;

    expect($diff)->toContain('extended')
        ->toContain('<ins>')
        ->toContain('&lt;b&gt;')
        ->not->toContain('<b>API</b>');
});

test('people comment, reply and resolve from the step', function () {
    $page = stepItem($this, $this->s->beta)->assertSee('0 comments')
        ->set('commentKind', 'question')->set('commentBody', 'Which status codes?')->call('addComment');
    $question = Comment::query()->firstOrFail();

    $page->set('replyTo', $question->id)->set('replyBody', 'See the error step.')->call('reply')
        ->call('resolve', $question->id)
        ->assertSee('2 comments')->assertSee('Which status codes?')->assertSee('See the error step.')->assertSee('Resolved');

    expect($question->fresh()?->resolved_at)->not->toBeNull()
        ->and($question->replies()->count())->toBe(1);
});

test('a reply to a reply joins its thread, so the step shows it', function () {
    // A second apart: replies are ordered by created_at, which MySQL stores to the second.
    $question = app(AddComment::class)->handle($this->s->beta, $this->step, CommentKind::Question, 'Which status codes?');
    $this->travel(1)->seconds();
    $answer = app(AddComment::class)->handle($this->s->alpha, $this->step, CommentKind::General, 'See the error step.', $question);
    $this->travel(1)->seconds();
    $followUp = app(AddComment::class)->handle($this->s->beta, $this->step, CommentKind::General, 'Including 409?', $answer);

    expect($followUp->parent_id)->toBe($question->id)
        ->and(ActivityEvent::query()->where('subject_id', $followUp->id)->sole()->payload)
        ->toMatchArray(['parent_id' => $question->id, 'in_reply_to' => $answer->id]);

    stepItem($this, $this->s->alpha)->assertSeeInOrder(['Which status codes?', 'See the error step.', 'Including 409?']);
});

test('editors propose archiving from the menu and can keep the step; it goes once the required parties approve', function () {
    stepItem($this, $this->s->party(ContractRole::Viewer))->assertDontSee('Propose archiving')->call('archive');
    expect($this->step->fresh()?->currentRevision?->proposes_archive)->toBeFalse();

    stepItem($this, $this->s->alpha)->call('approve', 1);
    stepItem($this, $this->s->alpha)->assertSee('Propose archiving')->call('archive')->assertDispatched('step-updated')
        ->assertSee('Archiving proposed')->assertSee('Keep step')
        ->call('keep')->assertDontSeeHtml('data-test="archive-proposal"');
    // Keeping it restores the approval of its text.
    expect($this->step->fresh()?->isArchived())->toBeFalse()
        ->and($this->s->readiness()->outstanding)->toBe([['step_id' => $this->step->id, 'party_id' => $this->s->beta->party?->id]]);

    stepItem($this, $this->s->alpha)->call('archive');
    stepItem($this, $this->s->beta)->assertSee('Click to approve archiving this step.')->call('approve', 4);
    expect($this->step->fresh()?->isArchived())->toBeFalse();

    stepItem($this, $this->s->alpha)->call('approve', 4);
    expect($this->step->fresh()?->isArchived())->toBeTrue();
});

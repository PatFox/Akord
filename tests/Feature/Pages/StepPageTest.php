<?php

use App\Actions\Comments\AddComment;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use App\Models\Comment;
use App\Models\StepRevision;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step('Build the API', 'Original text.');
});

function stepPage(object $t, $actor)
{
    test()->actingAs($actor->user);

    return Livewire::test('pages::steps.show', ['contract' => $t->s->contract, 'step' => $t->step]);
}

test('saving over someone else\'s edit shows a conflict and keeps the draft; saving again applies it on top', function () {
    $otherEditor = $this->s->party(ContractRole::Editor);
    $mine = stepPage($this, $this->s->alpha)->call('edit')->set('description', 'My wording.');
    stepPage($this, $otherEditor)->call('edit')->set('description', 'Their wording.')->call('save');

    $mine->call('save')
        ->assertSee('This step changed while you were editing')
        ->assertSet('description', 'My wording.')
        ->assertSet('editing', true);
    expect(StepRevision::query()->where('step_id', $this->step->id)->count())->toBe(2);

    $mine->call('rebase')->call('save')->assertSet('editing', false);
    expect($this->step->fresh()?->currentRevision?->only(['number', 'description_md']))
        ->toBe(['number' => 3, 'description_md' => 'My wording.']);
});

test('a party whose approval was cancelled by an edit is told so', function () {
    $this->s->approve($this->s->beta, $this->step);
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'Changed scope.');

    stepPage($this, $this->s->beta)->assertSee('Your approval was cancelled')->assertSee('Approve revision 2');
});

test('approving from the page approves the revision on screen', function () {
    stepPage($this, $this->s->beta)->call('approve', 1)->assertSee('You approved');

    expect($this->s->readiness()->outstanding)->toBe([['step_id' => $this->step->id, 'party_id' => $this->s->alpha->party->id]]);
});

test('descriptions and comments render Markdown without raw HTML or script links', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', "**Bold** <script>alert('x')</script> [click](javascript:alert(1)) <img src=x onerror=alert(1)>");
    app(AddComment::class)->handle($this->s->beta, $this->step, CommentKind::Question, '<iframe src="https://evil.test"></iframe> fine?');

    $html = $this->actingAs($this->s->beta->user)->get(route('steps.show', ['contract' => $this->s->contract, 'step' => $this->step]))->getContent();

    // The revision diff shows the Markdown source as escaped text, so look for live markup, not words.
    expect($html)->toContain('<strong>Bold</strong>')
        ->not->toContain("<script>alert('x')")
        ->not->toContain('href="javascript:')
        ->not->toContain('<img src=x')
        ->not->toContain('<iframe');
});

test('the history diff highlights changed words and escapes revision text', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the <b>API</b>', 'Original text, extended.');

    $page = stepPage($this, $this->s->alpha)->set('compareFrom', 1)->set('compareTo', 2);
    $diff = $page->instance()->diffHtml;

    expect($diff)->toContain('extended')
        ->toContain('<ins>')
        ->toContain('&lt;b&gt;')
        ->not->toContain('<b>API</b>');
});

test('people comment, reply and resolve from the page', function () {
    $page = stepPage($this, $this->s->beta)
        ->set('commentKind', 'question')->set('commentBody', 'Which status codes?')->call('addComment');
    $question = Comment::query()->firstOrFail();

    $page->set('replyTo', $question->id)->set('replyBody', 'See the error step.')->call('reply')
        ->call('resolve', $question->id)
        ->assertSee('Which status codes?')->assertSee('See the error step.')->assertSee('Resolved');

    expect($question->fresh()?->resolved_at)->not->toBeNull()
        ->and($question->replies()->count())->toBe(1);
});

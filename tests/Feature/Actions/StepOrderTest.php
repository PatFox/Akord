<?php

use App\Actions\Phases\CreatePhase;
use App\Actions\Steps\ArchiveStep;
use App\Actions\Steps\CreateStep;
use App\Actions\Steps\MoveStep;
use App\Models\Phase;
use App\Models\Step;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->a = $this->s->step('A');
    $this->b = $this->s->step('B');
    $this->c = $this->s->step('C');
});

/**
 * Titles of a phase's active steps in order; also asserts positions run 1..n with no gaps.
 *
 * @return list<string>
 */
function order(Phase $phase): array
{
    $steps = Step::query()->with('currentRevision')->where('phase_id', $phase->id)->active()->orderBy('position')->get();
    expect($steps->pluck('position')->all())->toBe($steps->isEmpty() ? [] : range(1, $steps->count()));

    return $steps->map(fn (Step $step) => $step->currentRevision->title)->all();
}

function createAfter(Scenario $s, Phase $phase, string $title, ?int $afterStepId): Step
{
    return app(CreateStep::class)->handle($s->alpha, $phase, $title, '', null, $afterStepId);
}

test('a new step goes at the end, at the start (0), or directly after a given step', function () {
    createAfter($this->s, $this->s->phase, 'D', null);
    createAfter($this->s, $this->s->phase, 'E', 0);
    createAfter($this->s, $this->s->phase, 'F', $this->b->id);

    expect(order($this->s->phase))->toBe(['E', 'A', 'B', 'F', 'C', 'D']);
});

test('moving within and across phases keeps both phases numbered without gaps', function () {
    $second = app(CreatePhase::class)->handle($this->s->alpha, $this->s->contract, 'Second');
    $move = fn (Step $step, Phase $to, ?int $after) => app(MoveStep::class)->handleAfter($this->s->alpha, $step, $to, $after);

    $move($this->b, $second, null);
    $move($this->a, $second, 0);
    $move($this->c, $this->s->phase, 0);
    $move($this->b, $second, 0);

    expect(order($this->s->phase))->toBe(['C'])
        ->and(order($second))->toBe(['B', 'A']);
});

test('an anchor that is the step itself, archived, or in another phase is refused and changes nothing', function () {
    $second = app(CreatePhase::class)->handle($this->s->alpha, $this->s->contract, 'Second');
    $elsewhere = createAfter($this->s, $second, 'X', null);
    app(ArchiveStep::class)->handle($this->s->alpha, $this->c);

    expect(status(fn () => createAfter($this->s, $this->s->phase, 'D', $this->c->id)))->toBe(422)
        ->and(status(fn () => createAfter($this->s, $this->s->phase, 'D', $elsewhere->id)))->toBe(422)
        ->and(status(fn () => app(MoveStep::class)->handleAfter($this->s->alpha, $this->a, $this->s->phase, $this->a->id)))->toBe(422)
        ->and(order($this->s->phase))->toBe(['A', 'B'])
        ->and(order($second))->toBe(['X']);
});

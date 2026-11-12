<?php

use App\Actions\Steps\ReviseStep;
use App\Enums\ContractRole;
use App\Models\StepRevision;
use Tests\Support\Scenario;

test('the second of two edits from the same revision is a conflict and the first is kept', function () {
    $s = Scenario::make();
    $step = $s->step('Original', 'Original text.');
    $otherEditor = $s->party(ContractRole::Editor);

    $first = status(fn () => app(ReviseStep::class)->handle($s->alpha, $step, 1, 'First edit', 'From alpha.'));
    $second = status(fn () => app(ReviseStep::class)->handle($otherEditor, $step, 1, 'Second edit', 'From the other editor.'));

    expect([$first, $second])->toBe([200, 409])
        ->and(StepRevision::query()->where('step_id', $step->id)->orderBy('number')->pluck('title')->all())->toBe(['Original', 'First edit'])
        ->and($step->fresh()?->currentRevision?->title)->toBe('First edit');
});

<?php

use App\Models\ActivityEvent;
use App\Support\ActivitySequencer;
use Tests\Support\Scenario;

test('writes are sequenced after commit', function () {
    $s = Scenario::make();
    $s->step();

    expect(ActivityEvent::query()->whereNull('seq')->count())->toBe(0)
        ->and(ActivityEvent::query()->after(0)->pluck('type')->all())->toBe(['step.created']);
});

test('an event that commits after a reader took its cursor is still delivered after the cursor', function () {
    $s = Scenario::make();
    $s->step();
    $cursor = (int) ActivityEvent::query()->max('seq');

    // Written by a transaction that commits only now.
    $late = ActivityEvent::factory()->create(['organisation_id' => $s->organisation->id, 'contract_id' => $s->contract->id]);
    ActivitySequencer::run();

    expect(ActivityEvent::query()->after($cursor)->pluck('id')->all())->toBe([$late->id]);
});

test('pending events are numbered in their contract’s order, not by id', function () {
    $s = Scenario::make();
    $attributes = ['organisation_id' => $s->organisation->id, 'contract_id' => $s->contract->id];

    // The later event gets the id that sorts first.
    ActivityEvent::factory()->create(['id' => 'zzzzzzzzzzzzzzzz', 'position' => 100, 'type' => 'test.first', ...$attributes]);
    ActivityEvent::factory()->create(['id' => '0000000000000000', 'position' => 101, 'type' => 'test.second', ...$attributes]);
    ActivitySequencer::run();

    expect(ActivityEvent::query()->after(0)->where('type', 'like', 'test.%')->pluck('type')->all())->toBe(['test.first', 'test.second']);
});

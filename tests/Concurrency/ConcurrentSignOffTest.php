<?php

use App\Models\ContractSignoff;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Scenario;

/**
 * Test 6. Two required parties sign off at the same moment from separate processes.
 * The contract row lock must serialise them: exactly one agreement, both sign-offs
 * kept. Without the lock, each transaction misses the other's sign-off and nobody
 * agrees the contract. Needs a server database (SQLite :memory: is per process).
 */
test('two simultaneous final sign-offs agree the contract exactly once', function () {
    $database = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $database['host'] ?? '',
        'DB_PORT' => (string) ($database['port'] ?? ''),
        'DB_DATABASE' => $database['database'] ?? '',
        'DB_USERNAME' => $database['username'] ?? '',
        'DB_PASSWORD' => $database['password'] ?? '',
    ];

    foreach (range(1, 5) as $round) {
        $s = Scenario::make();
        $s->approveAll($s->step());
        $fingerprint = $s->readiness()->fingerprint;
        $startAt = (string) (microtime(true) + 3);

        $processes = collect([$s->alpha, $s->beta])->map(fn ($actor) => tap(new Process([
            PHP_BINARY, base_path('tests/Concurrency/sign-off.php'),
            (string) $s->contract->id, (string) $actor->party->id, (string) $actor->user->id, $fingerprint, $startAt,
        ], env: $env, timeout: 60))->start());

        $statuses = $processes->map(fn (Process $p) => tap($p)->wait()->getOutput().$p->getErrorOutput())->all();

        expect($statuses)->toBe(['200', '200'], "round {$round}")
            ->and($s->contract->fresh()?->isAgreed())->toBeTrue("round {$round}: contract not agreed")
            ->and($s->activityCount('contract.agreed'))->toBe(1, "round {$round}")
            ->and(ContractSignoff::query()->where('contract_id', $s->contract->id)->active()->count())->toBe(2, "round {$round}");
    }
})->skip(fn () => DB::getDriverName() === 'sqlite', 'Needs MySQL or PostgreSQL: run the suite against a server database.');

<?php

namespace App\Support;

use App\Actions\Contracts\ContractReadiness;
use App\Enums\ContractAbility;
use App\Exceptions\ContractLockedException;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * The one way to change a contract. In order:
 *
 * 1. Lock the contract row. This is always the first statement and the first lock, so
 *    all writes to a contract are serialised in one lock order (no deadlocks), and on
 *    MySQL REPEATABLE READ every later plain read sees everything committed before the
 *    lock. Callers must therefore re-read related rows inside $work.
 * 2. Visibility (404), the actor's ability (403), then the lock guard (423).
 * 3. Run $work, then record readiness transitions and sequence activity after commit.
 */
final class ContractTransaction
{
    /**
     * @template TResult
     *
     * @param  callable(Contract, ActivityLog): TResult  $work
     * @return TResult
     */
    public static function run(
        Contract $contract,
        Actor $actor,
        ContractAbility $ability,
        callable $work,
        bool $allowWhenAgreed = false,
    ): mixed {
        return DB::transaction(function () use ($contract, $actor, $ability, $work, $allowWhenAgreed): mixed {
            $locked = Contract::query()->lockForUpdate()->whereKey($contract->getKey())->firstOrFail();

            ContractAccess::authorize($actor, $locked, $ability);

            if ($locked->isAgreed() && ! $allowWhenAgreed) {
                throw new ContractLockedException;
            }

            $log = new ActivityLog($locked, $actor);
            $before = $locked->isAgreed() ? null : ContractReadiness::report($locked);

            $result = $work($locked, $log);

            if ($before !== null && ! $locked->isAgreed()) {
                $log->readinessChanged($before, ContractReadiness::report($locked));
            }

            DB::afterCommit(ActivitySequencer::run(...));

            return $result;
        }, 3);
    }
}

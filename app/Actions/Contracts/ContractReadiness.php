<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Enums\ContractState;
use App\Models\Contract;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractFingerprint;
use App\Support\ReadinessReport;
use Illuminate\Support\Facades\DB;

/**
 * Read-only: the derived contract state, its fingerprint, the outstanding approvals,
 * and which required parties have signed off on the current fingerprint.
 *
 * An approval counts when it is active, comes from a required party, and its
 * revision's content hash equals the step's current revision's (D11).
 */
final class ContractReadiness
{
    public function handle(Actor $actor, Contract $contract): ReadinessReport
    {
        ContractAccess::authorize($actor, $contract, ContractAbility::View);

        return self::report($contract);
    }

    public static function report(Contract $contract): ReadinessReport
    {
        /** @var list<string> $requiredPartyIds */
        $requiredPartyIds = DB::table('contract_party')
            ->where('contract_id', $contract->getKey())
            ->where('is_required_approver', true)
            ->orderBy('party_id')
            ->pluck('party_id')
            ->all();

        /** @var array<string, string> $hashByStep step id => current content hash */
        $hashByStep = DB::table('steps')
            ->join('phases', 'phases.id', '=', 'steps.phase_id')
            ->join('step_revisions as current', 'current.id', '=', 'steps.current_revision_id')
            ->where('phases.contract_id', $contract->getKey())
            ->whereNull('steps.archived_at')
            ->orderBy('steps.id')
            ->pluck('current.content_hash', 'steps.id')
            ->all();

        $approved = self::countingApprovals(array_keys($hashByStep), $requiredPartyIds);

        $outstanding = [];
        foreach (array_keys($hashByStep) as $stepId) {
            foreach ($requiredPartyIds as $partyId) {
                if (! isset($approved[$stepId.':'.$partyId])) {
                    $outstanding[] = ['step_id' => $stepId, 'party_id' => $partyId];
                }
            }
        }

        $fingerprint = ContractFingerprint::compute($hashByStep, $requiredPartyIds);

        /** @var list<string> $signedOff */
        $signedOff = $requiredPartyIds === [] ? [] : DB::table('contract_signoffs')
            ->where('contract_id', $contract->getKey())
            ->where('fingerprint', $fingerprint)
            ->whereNull('withdrawn_at')
            ->whereIn('party_id', $requiredPartyIds)
            ->orderBy('party_id')
            ->pluck('party_id')
            ->unique()
            ->values()
            ->all();

        $state = match (true) {
            $contract->isAgreed() => ContractState::Agreed,
            $hashByStep !== [] && $requiredPartyIds !== [] && $outstanding === [] => ContractState::ReadyForSignoff,
            default => ContractState::Draft,
        };

        return new ReadinessReport($state, $fingerprint, count($hashByStep), $requiredPartyIds, $outstanding, $signedOff);
    }

    /**
     * The approvals that count among these steps and parties: active, and for a revision with the step's current
     * content hash (D11).
     *
     * @param  list<string>  $stepIds
     * @param  list<string>  $partyIds
     * @return array<string, true> "stepId:partyId"
     */
    public static function countingApprovals(array $stepIds, array $partyIds): array
    {
        if ($stepIds === [] || $partyIds === []) {
            return [];
        }

        $approved = [];
        DB::table('approvals')
            ->join('step_revisions as approved', 'approved.id', '=', 'approvals.step_revision_id')
            ->join('steps', 'steps.id', '=', 'approvals.step_id')
            ->join('step_revisions as current', 'current.id', '=', 'steps.current_revision_id')
            ->whereIn('approvals.step_id', $stepIds)
            ->whereIn('approvals.party_id', $partyIds)
            ->whereNull('approvals.withdrawn_at')
            ->whereColumn('approved.content_hash', 'current.content_hash')
            ->select('approvals.step_id', 'approvals.party_id')
            ->get()
            ->each(function (object $row) use (&$approved): void {
                /** @var object{step_id: string, party_id: string} $row */
                $approved[$row->step_id.':'.$row->party_id] = true;
            });

        return $approved;
    }
}

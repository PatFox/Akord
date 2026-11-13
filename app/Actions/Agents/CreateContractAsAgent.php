<?php

namespace App\Actions\Agents;

use App\Enums\ContractRole;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Project;
use App\Support\ActivityLog;
use App\Support\ActivitySequencer;
use App\Support\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An agent starts version 1 of a contract. People use Organisations\CreateContract (owners only, D4).
 *
 * - Needs the token's 'create' ability (403).
 * - Only in a project the agent can already see: one holding a contract its party is on (404 otherwise).
 * - The agent's party is attached as editor, not as a required approver; choosing required
 *   approvers stays with owners (D4), so the contract can't become ready until an owner does.
 */
final class CreateContractAsAgent
{
    public const ABILITY = 'create';

    public function handle(Actor $actor, int $projectId, string $title): Contract
    {
        if (! $actor->isAgent() || $actor->party === null) {
            throw new InvalidArgumentException('Only agents use this; people create contracts with CreateContract.');
        }

        if (! $actor->tokenAllows(self::ABILITY)) {
            throw new AuthorizationException("This token lacks the '".self::ABILITY."' ability.");
        }

        $party = $actor->party;
        $project = Project::query()->visibleTo($party)->findOrFail($projectId);

        return DB::transaction(function () use ($actor, $party, $project, $title): Contract {
            $contract = Contract::query()->create([
                'project_id' => $project->id,
                'title' => $title,
                'version' => 1,
                'require_human_signoff' => false,
            ]);

            ContractParty::query()->create([
                'contract_id' => $contract->id,
                'party_id' => $party->id,
                'role' => ContractRole::Editor,
                'is_required_approver' => false,
            ]);

            (new ActivityLog($contract, $actor))->record('contract.created', $contract, ['project_id' => $project->id]);
            DB::afterCommit(ActivitySequencer::run(...));

            return $contract;
        });
    }
}

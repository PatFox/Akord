<?php

namespace App\Support;

use App\Actions\Contracts\ContractReadiness;
use App\Enums\ContractAbility;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Party;
use App\Models\StepRevision;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What the contract page's step components show, loaded for all of the page's steps at once, so a long contract
 * costs a fixed number of queries instead of dozens per step. The page hands one to every step component it mounts,
 * for that first render only; afterwards (an action may have changed things) each step loads its own through the
 * same static helpers. Nothing loads until a step component asks, since the page re-renders often without mounting
 * any step.
 */
final class StepListing
{
    /**
     * @var array{current: Collection<string, StepRevision>, requiredParties: EloquentCollection<int, Party>, approved: array<string, true>, myApprovals: array<string, string>, threads: Collection<array-key, EloquentCollection<int, Comment>>, commentCounts: array<string, int>}|null
     */
    private ?array $loaded = null;

    /** @var array<string, bool> */
    private array $allowed = [];

    /**
     * @param  list<string>  $stepIds
     */
    public function __construct(
        private readonly Contract $contract,
        public readonly Actor $actor,
        private readonly array $stepIds,
    ) {}

    /**
     * Whether this listing can show the step: it was made for this contract and the step is one of its steps.
     */
    public function covers(Contract $contract, string $stepId): bool
    {
        return $contract->is($this->contract) && $this->load()['current']->has($stepId);
    }

    /**
     * The step's current revision, with its authors; only for a step the listing covers.
     */
    public function current(string $stepId): StepRevision
    {
        return $this->load()['current'][$stepId];
    }

    /**
     * @return EloquentCollection<int, Party> in the contract page's column order
     */
    public function requiredParties(): EloquentCollection
    {
        return $this->load()['requiredParties'];
    }

    /**
     * @return array<string, true> "stepId:partyId" for approvals that count
     */
    public function approved(): array
    {
        return $this->load()['approved'];
    }

    /**
     * The acting party's approval of the step, as in approvalState().
     */
    public function myApproval(string $stepId): string
    {
        return $this->load()['myApprovals'][$stepId] ?? 'none';
    }

    /**
     * @return Collection<int, Comment>
     */
    public function threads(string $stepId): Collection
    {
        return $this->load()['threads']->get($stepId, collect());
    }

    public function commentCount(string $stepId): int
    {
        return $this->load()['commentCounts'][$stepId] ?? 0;
    }

    /**
     * ContractAccess::allows for the acting party, once per ability for every step.
     */
    public function allows(ContractAbility $ability): bool
    {
        return $this->allowed[$ability->name] ??= ContractAccess::allows($this->actor, $this->contract, $ability);
    }

    /**
     * Top-level comments with their authors, revision and replies, oldest first, by step id.
     *
     * @param  list<string>  $stepIds
     * @return Collection<array-key, EloquentCollection<int, Comment>>
     */
    public static function threadsOf(array $stepIds): Collection
    {
        return Comment::query()
            ->whereIn('step_id', $stepIds)
            ->whereNull('parent_id')
            ->with(['authorParty', 'authorUser', 'revision', 'replies' => fn ($replies) => $replies->with(['authorParty', 'authorUser'])->oldest()])
            ->oldest()
            ->get()
            ->groupBy('step_id');
    }

    /**
     * Comments and replies, by step id; steps without any are left out.
     *
     * @param  list<string>  $stepIds
     * @return array<string, int>
     */
    public static function commentCountsOf(array $stepIds): array
    {
        return Comment::query()
            ->whereIn('step_id', $stepIds)
            ->groupBy('step_id')
            ->selectRaw('step_id, count(*) as aggregate')
            ->pluck('aggregate', 'step_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * The party's active approvals (with their revisions), by step id.
     *
     * @param  list<string>  $stepIds
     * @return Collection<string, EloquentCollection<int, Approval>>
     */
    public static function activeApprovalsOf(array $stepIds, Party $party): Collection
    {
        return Approval::query()->active()->with('revision')->whereIn('step_id', $stepIds)->where('party_id', $party->id)->get()->groupBy('step_id');
    }

    /**
     * A party's approval of a step: 'counts', 'cancelled' (it approved content that has since changed) or 'none'.
     *
     * @param  iterable<int, Approval>  $active  the party's active approvals of the step
     */
    public static function approvalState(iterable $active, StepRevision $current): string
    {
        $active = collect($active);

        return match (true) {
            $active->contains(fn (Approval $a) => $a->revision->content_hash === $current->content_hash) => 'counts',
            $active->isNotEmpty() => 'cancelled',
            default => 'none',
        };
    }

    /**
     * @return array{current: Collection<string, StepRevision>, requiredParties: EloquentCollection<int, Party>, approved: array<string, true>, myApprovals: array<string, string>, threads: Collection<array-key, EloquentCollection<int, Comment>>, commentCounts: array<string, int>}
     */
    private function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        // Joined through the phases, so only this contract's steps are listed.
        /** @var Collection<string, StepRevision> $current */
        $current = StepRevision::query()
            ->select('step_revisions.*')
            ->join('steps', 'steps.current_revision_id', '=', 'step_revisions.id')
            ->join('phases', 'phases.id', '=', 'steps.phase_id')
            ->where('phases.contract_id', $this->contract->getKey())
            ->whereIn('steps.id', $this->stepIds)
            ->with(['authorParty', 'authorUser'])
            ->get()
            ->keyBy('step_id');
        /** @var list<string> $stepIds */
        $stepIds = $current->keys()->all();

        $requiredParties = $this->contract->requiredApprovers()->orderBy('name')->orderBy('parties.id')->get();
        /** @var list<string> $requiredPartyIds */
        $requiredPartyIds = $requiredParties->modelKeys();

        $myApprovals = [];
        if ($this->actor->party !== null) {
            foreach (self::activeApprovalsOf($stepIds, $this->actor->party) as $stepId => $active) {
                $myApprovals[$stepId] = self::approvalState($active, $current[$stepId]);
            }
        }

        return $this->loaded = [
            'current' => $current,
            'requiredParties' => $requiredParties,
            'approved' => ContractReadiness::countingApprovals($stepIds, $requiredPartyIds),
            'myApprovals' => $myApprovals,
            'threads' => self::threadsOf($stepIds),
            'commentCounts' => self::commentCountsOf($stepIds),
        ];
    }
}

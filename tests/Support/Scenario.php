<?php

namespace Tests\Support;

use App\Actions\Approvals\ApproveStep;
use App\Actions\Contracts\ContractReadiness;
use App\Actions\Steps\CreateStep;
use App\Enums\ContractRole;
use App\Enums\OrganisationRole;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Organisation;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Step;
use App\Models\User;
use App\Support\Actor;
use App\Support\ReadinessReport;

/**
 * An organisation with an owner, one contract with one phase, and two required
 * human parties: alpha (editor) and beta (commenter). Parties are attached with
 * direct writes (fixture), everything else goes through the actions.
 */
final class Scenario
{
    public Organisation $organisation;

    public User $owner;

    public Contract $contract;

    public Phase $phase;

    public Actor $alpha;

    public Actor $beta;

    public static function make(): self
    {
        $scenario = new self;
        $scenario->organisation = Organisation::factory()->create();
        $scenario->owner = User::factory()->create();
        $scenario->organisation->users()->attach($scenario->owner, ['role' => OrganisationRole::Owner->value]);
        $project = Project::factory()->for($scenario->organisation)->create();
        $scenario->contract = Contract::factory()->for($project)->create();
        $scenario->phase = Phase::factory()->for($scenario->contract)->create(['title' => 'Phase one', 'position' => 1]);
        $scenario->alpha = $scenario->party(ContractRole::Editor, required: true);
        $scenario->beta = $scenario->party(ContractRole::Commenter, required: true);

        return $scenario;
    }

    /**
     * A new party of the organisation attached to the contract, with one member
     * (or an agent party), returned as an actor.
     *
     * @param  list<string>  $abilities  agent token abilities
     */
    public function party(?ContractRole $role, bool $required = false, bool $agent = false, array $abilities = ['*']): Actor
    {
        $party = $agent
            ? Party::factory()->for($this->organisation)->agent()->create()
            : Party::factory()->for($this->organisation)->create();

        if ($role !== null) {
            ContractParty::query()->create([
                'contract_id' => $this->contract->id,
                'party_id' => $party->id,
                'role' => $role,
                'is_required_approver' => $required,
            ]);
        }

        if ($agent) {
            return Actor::agent($party, $abilities);
        }

        $user = User::factory()->create();
        $party->users()->attach($user);

        return Actor::user($user, $party);
    }

    public function ownerActor(?Party $party = null): Actor
    {
        return Actor::user($this->owner, $party);
    }

    public function step(string $title = 'Build the API', string $description = 'Endpoints for contracts.', ?Phase $phase = null): Step
    {
        return app(CreateStep::class)->handle($this->alpha, $phase ?? $this->phase, $title, $description);
    }

    public function approve(Actor $actor, Step $step): void
    {
        app(ApproveStep::class)->handle($actor, $step, $step->fresh()?->currentRevision?->number ?? 1);
    }

    /**
     * Every required party approves every given step.
     */
    public function approveAll(Step ...$steps): void
    {
        foreach ($steps as $step) {
            $this->approve($this->alpha, $step);
            $this->approve($this->beta, $step);
        }
    }

    public function readiness(): ReadinessReport
    {
        return ContractReadiness::report($this->contract->fresh() ?? $this->contract);
    }

    public function activityCount(string $type): int
    {
        return $this->contract->activityEvents()->where('type', $type)->count();
    }
}

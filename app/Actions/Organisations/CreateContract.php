<?php

namespace App\Actions\Organisations;

use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Support\OrganisationAccess;

/**
 * Owner-only (D4): starts version 1 of a contract in a project. Parties are attached afterwards.
 */
final class CreateContract
{
    public function handle(User $user, Project $project, string $title, bool $requireHumanSignoff = false): Contract
    {
        OrganisationAccess::ensureOwner($user, $project->organisation_id);

        return Contract::query()->create([
            'project_id' => $project->id,
            'title' => $title,
            'version' => 1,
            'require_human_signoff' => $requireHumanSignoff,
        ]);
    }
}

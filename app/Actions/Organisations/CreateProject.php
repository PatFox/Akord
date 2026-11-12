<?php

namespace App\Actions\Organisations;

use App\Models\Organisation;
use App\Models\Project;
use App\Models\User;
use App\Support\OrganisationAccess;

/**
 * Owner-only (D4): adds a project to an organisation.
 */
final class CreateProject
{
    public function handle(User $user, Organisation $organisation, string $name): Project
    {
        OrganisationAccess::ensureOwner($user, $organisation->id);

        return Project::query()->create(['organisation_id' => $organisation->id, 'name' => $name]);
    }
}

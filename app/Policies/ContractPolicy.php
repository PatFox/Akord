<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Web-side entry to the access rule in App\Support\ContractAccess. Contracts the
 * user cannot see are "not found" (404), never "forbidden".
 */
class ContractPolicy
{
    public function view(User $user, Contract $contract): Response
    {
        return Contract::query()->visibleTo($user)->whereKey($contract->getKey())->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function administer(User $user, Contract $contract): Response
    {
        $view = $this->view($user, $contract);

        if ($view->denied()) {
            return $view;
        }

        return $user->isOwnerOf($contract->organisationId())
            ? Response::allow()
            : Response::deny('Only organisation owners can manage this contract.');
    }
}

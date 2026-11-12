<?php

use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Live activity for one contract: anyone who can view the contract (ContractPolicy).
Broadcast::channel('contract.{contract}', function (User $user, Contract $contract) {
    return Gate::forUser($user)->allows('view', $contract);
});

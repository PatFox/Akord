<?php

namespace App\Http\Controllers;

use App\Actions\Contracts\ContractReadiness;
use App\Models\Contract;
use App\Support\ContractPlan;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /contracts/{contract}/plan.md: the plan document as a file. Contracts the person cannot see are 404.
 */
class DownloadContractPlan
{
    public function __invoke(Contract $contract): Response
    {
        Gate::authorize('view', $contract);

        return response()->streamDownload(
            fn () => print (ContractPlan::markdown($contract, ContractReadiness::report($contract))),
            ContractPlan::filename($contract),
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }
}

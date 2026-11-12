<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends AgentApiController
{
    /**
     * POST /api/v1/contracts/{id}/steps/{id}/approval {revision}: approve the revision you reviewed.
     * 409 if the step's content changed since that revision.
     */
    public function store(Request $request, int $contract, int $step): JsonResponse
    {
        $v = $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        return $this->respond($this->api->approveStep($this->actor($request), $contract, $step, (int) $v['revision']));
    }

    public function destroy(Request $request, int $contract, int $step): JsonResponse
    {
        return $this->respond($this->api->withdrawApproval($this->actor($request), $contract, $step));
    }
}

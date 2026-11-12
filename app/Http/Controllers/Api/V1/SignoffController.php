<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignoffController extends AgentApiController
{
    /**
     * POST /api/v1/contracts/{id}/signoffs {fingerprint}
     * 409 stale fingerprint, 422 not ready, 423 agreed, 403 not a required approver or D10.
     */
    public function store(Request $request, int $contract): JsonResponse
    {
        $v = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);

        return $this->respond($this->api->signOff($this->actor($request), $contract, $v['fingerprint']), 201);
    }

    public function destroyMine(Request $request, int $contract): JsonResponse
    {
        return $this->respond($this->api->withdrawSignOff($this->actor($request), $contract));
    }
}

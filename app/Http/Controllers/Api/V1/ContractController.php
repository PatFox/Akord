<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContractController extends AgentApiController
{
    /**
     * GET /api/v1/contracts: contracts this agent's party is attached to.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->respond($this->api->listContracts($this->actor($request)));
    }

    /**
     * GET /api/v1/contracts/{id}[?format=md|json]
     */
    public function show(Request $request, int $contract): JsonResponse|Response
    {
        $format = $request->validate(['format' => ['nullable', 'in:json,md']])['format'] ?? 'json';

        if ($format === 'md') {
            return response($this->api->contractMarkdown($this->actor($request), $contract), 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
        }

        return $this->respond($this->api->contract($this->actor($request), $contract));
    }

    /**
     * GET /api/v1/contracts/{id}/status
     */
    public function status(Request $request, int $contract): JsonResponse
    {
        return $this->respond($this->api->status($this->actor($request), $contract));
    }
}

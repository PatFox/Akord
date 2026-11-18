<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhaseController extends AgentApiController
{
    /**
     * POST /api/v1/contracts/{id}/phases {title}: a phase at the end of the contract.
     */
    public function store(Request $request, string $contract): JsonResponse
    {
        $v = $request->validate(['title' => ['required', 'string', 'max:200']]);

        return $this->respond($this->api->createPhase($this->actor($request), $contract, $v['title']), 201);
    }

    /**
     * POST /api/v1/contracts/{id}/phases/{id}/steps {title, description_md, change_note?, after_step_id?}
     * after_step_id: omitted for the end of the phase, "start" for the start, else directly after that step.
     */
    public function storeStep(Request $request, string $contract, string $phase): JsonResponse
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description_md' => ['present', 'nullable', 'string', 'max:20000'],
            'change_note' => ['nullable', 'string', 'max:500'],
            'after_step_id' => ['nullable', 'string'],
        ]);

        return $this->respond($this->api->createStep(
            $this->actor($request), $contract, $phase, $v['title'], $v['description_md'] ?? '', $v['change_note'] ?? null,
            $v['after_step_id'] ?? null,
        ), 201);
    }
}

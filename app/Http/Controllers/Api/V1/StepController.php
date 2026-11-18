<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StepController extends AgentApiController
{
    public function show(Request $request, string $contract, string $step): JsonResponse
    {
        return $this->respond($this->api->step($this->actor($request), $contract, $step));
    }

    /**
     * PUT /api/v1/contracts/{id}/steps/{id} {base_revision, title, description_md, change_note?}
     * 409 if base_revision is no longer the current revision.
     */
    public function update(Request $request, string $contract, string $step): JsonResponse
    {
        $v = $request->validate([
            'base_revision' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:200'],
            'description_md' => ['present', 'nullable', 'string', 'max:20000'],
            'change_note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->respond($this->api->reviseStep(
            $this->actor($request), $contract, $step, (int) $v['base_revision'], $v['title'], $v['description_md'] ?? '', $v['change_note'] ?? null,
        ));
    }

    public function revisions(Request $request, string $contract, string $step): JsonResponse
    {
        return $this->respond($this->api->stepHistory($this->actor($request), $contract, $step));
    }

    /**
     * POST /api/v1/contracts/{id}/steps/{id}/move {phase_id?, after_step_id?}
     * phase_id omitted: same phase. after_step_id omitted: end of the phase; "start": start.
     */
    public function move(Request $request, string $contract, string $step): JsonResponse
    {
        $v = $request->validate([
            'phase_id' => ['nullable', 'string'],
            'after_step_id' => ['nullable', 'string'],
        ]);

        return $this->respond($this->api->moveStep(
            $this->actor($request), $contract, $step,
            $v['phase_id'] ?? null,
            $v['after_step_id'] ?? null,
        ));
    }

    /**
     * POST /api/v1/contracts/{id}/steps/{id}/archive. Cannot be undone.
     */
    public function archive(Request $request, string $contract, string $step): JsonResponse
    {
        return $this->respond($this->api->archiveStep($this->actor($request), $contract, $step));
    }

    /**
     * GET /api/v1/contracts/{id}/steps/{id}/diff?from=1&to=2
     */
    public function diff(Request $request, string $contract, string $step): JsonResponse
    {
        $v = $request->validate(['from' => ['required', 'integer', 'min:1'], 'to' => ['required', 'integer', 'min:1']]);

        return $this->respond($this->api->diff($this->actor($request), $contract, $step, (int) $v['from'], (int) $v['to']));
    }
}

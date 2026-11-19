<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Checklists\ChecklistInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A contract version's working checklist. Writes need the 'edit' ability and an editor party; they work on agreed
 * versions, and are 423 on complete or cancelled ones.
 */
class ChecklistController extends AgentApiController
{
    /**
     * GET /api/v1/contracts/{id}/checklist: {checklist: null} when there is none.
     */
    public function show(Request $request, string $contract): JsonResponse
    {
        return $this->respond($this->api->checklist($this->actor($request), $contract));
    }

    /**
     * PUT /api/v1/contracts/{id}/checklist {stages?: [{title, items: [{title, notes?, step_id?}]}], replace?}
     * Without stages the checklist copies the contract's phases and steps. 422 when replacing ticked items without
     * replace.
     */
    public function store(Request $request, string $contract): JsonResponse
    {
        $v = $request->validate(ChecklistInput::rules());

        return $this->respond($this->api->createChecklist($this->actor($request), $contract, $v['stages'] ?? null, (bool) ($v['replace'] ?? false)), 201);
    }

    /**
     * POST /api/v1/contracts/{id}/checklist/stages/{stage}/items {title, notes?, step_id?}: added at the end.
     */
    public function storeItem(Request $request, string $contract, string $stage): JsonResponse
    {
        $v = $request->validate(ChecklistInput::itemRules());

        return $this->respond($this->api->addChecklistItem($this->actor($request), $contract, $stage, $v['title'], $v['notes'] ?? null, $v['step_id'] ?? null), 201);
    }

    /**
     * POST /api/v1/contracts/{id}/checklist/items/{item}/check
     */
    public function check(Request $request, string $contract, string $item): JsonResponse
    {
        return $this->respond($this->api->setChecklistItemChecked($this->actor($request), $contract, $item, true));
    }

    /**
     * POST /api/v1/contracts/{id}/checklist/items/{item}/uncheck
     */
    public function uncheck(Request $request, string $contract, string $item): JsonResponse
    {
        return $this->respond($this->api->setChecklistItemChecked($this->actor($request), $contract, $item, false));
    }
}

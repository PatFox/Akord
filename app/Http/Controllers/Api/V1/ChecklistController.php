<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Checklists\ChecklistInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A contract version's working checklists (several, each named). Writes need the 'edit' ability and an editor party;
 * they work on agreed versions, and are 423 on complete or cancelled ones.
 */
class ChecklistController extends AgentApiController
{
    /**
     * GET /api/v1/contracts/{id}/checklists: every checklist, oldest first ([] when there are none).
     */
    public function index(Request $request, string $contract): JsonResponse
    {
        return $this->respond($this->api->checklists($this->actor($request), $contract));
    }

    /**
     * POST /api/v1/contracts/{id}/checklists {name, stages?: [{title, items: [{title, notes?, step_id?}]}], replace?}
     * Without stages the checklist copies the contract's phases and steps. An existing name (ignoring case) is replaced;
     * 422 when it has ticked items and replace is not set.
     */
    public function store(Request $request, string $contract): JsonResponse
    {
        $v = $request->validate(ChecklistInput::rules());

        return $this->respond($this->api->createChecklist($this->actor($request), $contract, $v['name'], $v['stages'] ?? null, (bool) ($v['replace'] ?? false)), 201);
    }

    /**
     * POST /api/v1/contracts/{id}/checklists/stages/{stage}/items {title, notes?, step_id?}: added at the end.
     */
    public function storeItem(Request $request, string $contract, string $stage): JsonResponse
    {
        $v = $request->validate(ChecklistInput::itemRules());

        return $this->respond($this->api->addChecklistItem($this->actor($request), $contract, $stage, $v['title'], $v['notes'] ?? null, $v['step_id'] ?? null), 201);
    }

    /**
     * POST /api/v1/contracts/{id}/checklists/items/{item}/check
     */
    public function check(Request $request, string $contract, string $item): JsonResponse
    {
        return $this->respond($this->api->setChecklistItemChecked($this->actor($request), $contract, $item, true));
    }

    /**
     * POST /api/v1/contracts/{id}/checklists/items/{item}/uncheck
     */
    public function uncheck(Request $request, string $contract, string $item): JsonResponse
    {
        return $this->respond($this->api->setChecklistItemChecked($this->actor($request), $contract, $item, false));
    }
}

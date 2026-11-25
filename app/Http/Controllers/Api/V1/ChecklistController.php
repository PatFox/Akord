<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Checklists\ChecklistInput;
use App\Actions\Checklists\UpdateChecklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A contract version's working checklists (several, each named). Writes need the 'edit' ability and an editor party;
 * ticking and unticking also work for a party assigned to the checklist, whatever its role (D18). They work on agreed
 * versions, and are 423 on complete or cancelled ones. Items can be added, changed, moved and deleted.
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
     * PATCH /api/v1/contracts/{id}/checklists/items/{item} {title?, notes?, step_id?}: only the fields given change;
     * null notes or step_id clear them. The tick stays.
     */
    public function updateItem(Request $request, string $contract, string $item): JsonResponse
    {
        $rules = ChecklistInput::itemRules();
        $rules['title'] = ['sometimes', ...$rules['title']];
        $v = $request->validate($rules);

        return $this->respond($this->api->updateChecklistItem($this->actor($request), $contract, $item, array_intersect_key($v, array_flip(UpdateChecklistItem::FIELDS))));
    }

    /**
     * DELETE /api/v1/contracts/{id}/checklists/items/{item}: ticked or not; cannot be undone.
     */
    public function destroyItem(Request $request, string $contract, string $item): JsonResponse
    {
        return $this->respond($this->api->deleteChecklistItem($this->actor($request), $contract, $item));
    }

    /**
     * POST /api/v1/contracts/{id}/checklists/items/{item}/move {stage_id?, after_item_id?}
     * stage_id omitted: same stage (any stage of the same checklist). after_item_id omitted: end of the stage; "start": start.
     */
    public function moveItem(Request $request, string $contract, string $item): JsonResponse
    {
        $v = $request->validate(['stage_id' => ['nullable', 'string'], 'after_item_id' => ['nullable', 'string']]);

        return $this->respond($this->api->moveChecklistItem($this->actor($request), $contract, $item, $v['stage_id'] ?? null, $v['after_item_id'] ?? null));
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

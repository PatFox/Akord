<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContractStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ContractController extends AgentApiController
{
    /**
     * GET /api/v1/contracts[?status[]=open&status[]=complete]: contracts this agent's party is attached to,
     * open ones only unless statuses are given (D14).
     */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['status' => ['sometimes', 'array'], 'status.*' => ['string', Rule::enum(ContractStatus::class)]]);

        return $this->respond($this->api->listContracts($this->actor($request), array_values(array_map(ContractStatus::from(...), $v['status'] ?? []))));
    }

    /**
     * POST /api/v1/contracts {project_id, title}: needs the 'create' ability; the agent's party
     * becomes an editor. Projects: only those holding a contract the agent is on (else 404).
     */
    public function store(Request $request): JsonResponse
    {
        $v = $request->validate(['project_id' => ['required', 'string'], 'title' => ['required', 'string', 'max:200']]);

        return $this->respond($this->api->createContract($this->actor($request), $v['project_id'], $v['title']), 201);
    }

    /**
     * GET /api/v1/contracts/{id}[?format=md|json]
     */
    public function show(Request $request, string $contract): JsonResponse|Response
    {
        $format = $request->validate(['format' => ['nullable', 'in:json,md']])['format'] ?? 'json';

        if ($format === 'md') {
            return response($this->api->contractMarkdown($this->actor($request), $contract), 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
        }

        return $this->respond($this->api->contract($this->actor($request), $contract));
    }

    /**
     * PATCH /api/v1/contracts/{id} {status}: open, complete or cancelled (D14). Needs the 'edit' ability and an
     * editor party; 422 on a superseded version.
     */
    public function update(Request $request, string $contract): JsonResponse
    {
        $v = $request->validate(['status' => ['required', 'string', Rule::enum(ContractStatus::class)]]);

        return $this->respond($this->api->setStatus($this->actor($request), $contract, ContractStatus::from($v['status'])));
    }

    /**
     * GET /api/v1/contracts/{id}/status
     */
    public function status(Request $request, string $contract): JsonResponse
    {
        return $this->respond($this->api->status($this->actor($request), $contract));
    }
}

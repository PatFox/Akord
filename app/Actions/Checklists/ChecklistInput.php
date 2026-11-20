<?php

namespace App\Actions\Checklists;

use App\Exceptions\ContractLockedException;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\Step;

/**
 * Shared by the checklist actions and the REST and MCP entry points: input rules, and the rule that a checklist
 * changes only while its contract version is open (agreed or not, D14).
 */
final class ChecklistInput
{
    public const MAX_ITEMS = 500;

    public const MAX_CHECKLISTS = 20;

    /**
     * Validation rules for a checklist, as sent to create_checklist / POST checklists.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'stages' => ['nullable', 'list', 'max:100'],
            'stages.*.title' => ['required', 'string', 'max:200'],
            'stages.*.items' => ['present', 'list', 'max:'.self::MAX_ITEMS],
            ...self::itemRules('stages.*.items.*.'),
            'replace' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function itemRules(string $prefix = ''): array
    {
        return [
            $prefix.'title' => ['required', 'string', 'max:300'],
            $prefix.'notes' => ['nullable', 'string', 'max:5000'],
            $prefix.'step_id' => ['nullable', 'string'],
        ];
    }

    public static function ensureOpen(Contract $contract): void
    {
        if (! $contract->isOpen()) {
            throw new ContractLockedException("This contract is marked {$contract->status->value}. Reopen it to change its checklist.");
        }
    }

    /**
     * An item may point at an active step of the same contract version; anything else is refused.
     */
    public static function stepId(Contract $contract, mixed $stepId): ?string
    {
        if ($stepId === null || $stepId === '') {
            return null;
        }

        $exists = is_string($stepId) && Step::query()->whereKey($stepId)->active()->whereRelation('phase', 'contract_id', $contract->getKey())->exists();

        return $exists ? $stepId : throw new InvalidActionException('step_id must be an active step of this contract.');
    }
}

<?php

namespace App\Models;

use App\Enums\ContractRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A party's role on a contract, inherited by every member of the party.
 *
 * @property int $id
 * @property int $contract_id
 * @property int $party_id
 * @property ContractRole $role
 * @property bool $is_required_approver
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContractParty extends Pivot
{
    protected $table = 'contract_party';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ContractRole::class,
            'is_required_approver' => 'boolean',
        ];
    }
}

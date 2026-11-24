<?php

namespace App\Exceptions;

use App\Models\Contract;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Any write to a locked contract: agreed (D3) or no longer open (D14). Invitations, the status
 * itself, starting a new version and, on open agreed versions, role changes (D17) are exempt.
 */
class ContractLockedException extends HttpException
{
    public function __construct(string $message = 'This contract is agreed and locked. Start a new version to make changes.')
    {
        parent::__construct(423, $message);
    }

    public static function for(Contract $contract): self
    {
        return $contract->isAgreed()
            ? new self
            : new self("This contract is marked {$contract->status->value}. Reopen it to make changes.");
    }
}

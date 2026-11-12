<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Any write to an agreed contract, except starting a new version.
 */
class ContractLockedException extends HttpException
{
    public function __construct(string $message = 'This contract is agreed and locked. Start a new version to make changes.')
    {
        parent::__construct(423, $message);
    }
}

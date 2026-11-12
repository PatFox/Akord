<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The caller signed off on a contract state that is no longer current.
 */
class StaleFingerprintException extends HttpException
{
    public function __construct(string $message = 'The contract has changed since you reviewed it. Review it again before signing off.')
    {
        parent::__construct(409, $message);
    }
}

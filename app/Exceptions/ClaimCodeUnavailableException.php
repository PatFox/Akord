<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An agent claim code that has been used or has expired (410 Gone).
 */
class ClaimCodeUnavailableException extends HttpException
{
    public function __construct(string $message = 'This claim code can no longer be used.')
    {
        parent::__construct(410, $message);
    }
}

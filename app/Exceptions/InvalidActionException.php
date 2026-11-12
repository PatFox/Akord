<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The request is well-formed but the contract's state or rules forbid it.
 */
class InvalidActionException extends HttpException
{
    public function __construct(string $message = 'This action is not allowed in the current state of the contract.')
    {
        parent::__construct(422, $message);
    }
}

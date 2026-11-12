<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The caller acted on a step revision that is no longer current (simultaneous edits).
 */
class StaleRevisionException extends HttpException
{
    public function __construct(string $message = 'This step has changed since you last saw it. Review the latest revision and try again.')
    {
        parent::__construct(409, $message);
    }
}

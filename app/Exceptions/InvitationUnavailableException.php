<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An invitation link that has been used, cancelled or has expired (410 Gone).
 */
class InvitationUnavailableException extends HttpException
{
    public function __construct(string $message = 'This invitation can no longer be used.')
    {
        parent::__construct(410, $message);
    }
}

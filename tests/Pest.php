<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\ConcurrencyTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(ConcurrencyTestCase::class)->in('Concurrency');

/**
 * The HTTP status an action's outcome maps to: 200 when it succeeds, otherwise the
 * status Laravel renders for the exception it throws.
 */
function status(callable $action): int
{
    try {
        $action();
    } catch (HttpExceptionInterface $e) {
        return $e->getStatusCode();
    } catch (AuthorizationException) {
        return 403;
    } catch (ModelNotFoundException) {
        return 404;
    }

    return 200;
}

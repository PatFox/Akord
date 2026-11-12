<?php

namespace App\Livewire\Concerns;

use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Runs a domain action from a Livewire component, turning its refusals
 * (403/404/409/422/423) into a toast instead of an error page.
 */
trait RunsActions
{
    /**
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|null null when the action was refused
     */
    protected function attempt(callable $action, ?string $success = null): mixed
    {
        try {
            $result = $action();
        } catch (HttpExceptionInterface $e) {
            $this->refused($e->getMessage() ?: 'That is not possible right now.', $e->getStatusCode());

            return null;
        } catch (AuthorizationException $e) {
            $this->refused($e->getMessage(), 403);

            return null;
        } catch (ModelNotFoundException) {
            $this->refused('That item no longer exists or is not visible to you.', 404);

            return null;
        }

        if ($success !== null) {
            Flux::toast(variant: 'success', text: $success);
        }

        return $result;
    }

    protected function refused(string $message, int $status): void
    {
        Flux::toast(variant: 'danger', text: $message);
    }
}

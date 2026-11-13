<?php

namespace App\Mcp\Tools;

use App\Support\Actor;
use App\Support\Agents\AgentActor;
use App\Support\Agents\AgentApi;
use App\Support\Agents\UntrustedContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Base for Akord's MCP tools: same operations as the REST API (AgentApi), replies
 * wrapped as data for the model, refusals returned as tool errors with the HTTP status.
 */
abstract class AgentTool extends Tool
{
    /**
     * @param  callable(AgentApi, Actor): array<string, mixed>  $operation
     */
    protected function run(Request $request, callable $operation): Response
    {
        try {
            $data = $operation(app(AgentApi::class), AgentActor::from($request->user(), AgentActor::urlPartyId()));
        } catch (ValidationException $e) {
            return Response::error('422: '.implode(' ', $e->validator->errors()->all()));
        } catch (HttpExceptionInterface $e) {
            return Response::error($e->getStatusCode().': '.$e->getMessage());
        } catch (AuthorizationException $e) {
            return Response::error('403: '.$e->getMessage());
        } catch (ModelNotFoundException) {
            return Response::error('404: Not found (or not visible to you).');
        }

        return Response::text(UntrustedContent::forModel($data));
    }
}

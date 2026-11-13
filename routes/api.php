<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PhaseController;
use App\Http\Controllers\Api\V1\SignoffController;
use App\Http\Controllers\Api\V1\StepController;
use Illuminate\Support\Facades\Route;

/*
| Agent REST API (Phase 7). Authenticated with agent credentials: claim-code tokens
| (Sanctum) or MCP OAuth tokens (Passport); rate limited per agent. Contracts the agent
| cannot see are 404.
*/

Route::prefix('v1')->group(function () {
    Route::post('agent/claim', ClaimController::class)->middleware('throttle:agent-claim');

    Route::middleware(['auth:sanctum,api', 'throttle:agent'])->group(function () {
        Route::get('agent', MeController::class);
        Route::get('activity', ActivityController::class);

        Route::get('contracts', [ContractController::class, 'index']);
        Route::get('contracts/{contract}', [ContractController::class, 'show'])->whereNumber('contract');
        Route::get('contracts/{contract}/status', [ContractController::class, 'status'])->whereNumber('contract');
        Route::post('contracts/{contract}/signoffs', [SignoffController::class, 'store'])->whereNumber('contract');
        Route::delete('contracts/{contract}/signoffs/mine', [SignoffController::class, 'destroyMine'])->whereNumber('contract');
        Route::post('contracts/{contract}/phases', [PhaseController::class, 'store'])->whereNumber('contract');
        Route::post('contracts/{contract}/phases/{phase}/steps', [PhaseController::class, 'storeStep'])->whereNumber(['contract', 'phase']);

        Route::prefix('contracts/{contract}/steps/{step}')->whereNumber(['contract', 'step'])->group(function () {
            Route::get('/', [StepController::class, 'show']);
            Route::put('/', [StepController::class, 'update']);
            Route::get('revisions', [StepController::class, 'revisions']);
            Route::get('diff', [StepController::class, 'diff']);
            Route::post('move', [StepController::class, 'move']);
            Route::post('archive', [StepController::class, 'archive']);
            Route::post('comments', [CommentController::class, 'store']);
            Route::post('approval', [ApprovalController::class, 'store']);
            Route::delete('approval', [ApprovalController::class, 'destroy']);
        });
    });
});

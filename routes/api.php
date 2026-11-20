<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\ChecklistController;
use App\Http\Controllers\Api\V1\ClaimController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PhaseController;
use App\Http\Controllers\Api\V1\SignoffController;
use App\Http\Controllers\Api\V1\StepController;
use App\Support\RandomId;
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
        Route::post('contracts', [ContractController::class, 'store']);
        Route::get('contracts/{contract}', [ContractController::class, 'show'])->where('contract', RandomId::PATTERN);
        Route::patch('contracts/{contract}', [ContractController::class, 'update'])->where('contract', RandomId::PATTERN);
        Route::get('contracts/{contract}/status', [ContractController::class, 'status'])->where('contract', RandomId::PATTERN);
        Route::post('contracts/{contract}/signoffs', [SignoffController::class, 'store'])->where('contract', RandomId::PATTERN);
        Route::delete('contracts/{contract}/signoffs/mine', [SignoffController::class, 'destroyMine'])->where('contract', RandomId::PATTERN);
        Route::post('contracts/{contract}/phases', [PhaseController::class, 'store'])->where('contract', RandomId::PATTERN);
        Route::post('contracts/{contract}/phases/{phase}/steps', [PhaseController::class, 'storeStep'])->where(['contract' => RandomId::PATTERN, 'phase' => RandomId::PATTERN]);

        Route::prefix('contracts/{contract}/checklists')->where(['contract' => RandomId::PATTERN, 'stage' => RandomId::PATTERN, 'item' => RandomId::PATTERN])->group(function () {
            Route::get('/', [ChecklistController::class, 'index']);
            Route::post('/', [ChecklistController::class, 'store']);
            Route::post('stages/{stage}/items', [ChecklistController::class, 'storeItem']);
            Route::post('items/{item}/check', [ChecklistController::class, 'check']);
            Route::post('items/{item}/uncheck', [ChecklistController::class, 'uncheck']);
        });

        Route::prefix('contracts/{contract}/steps/{step}')->where(['contract' => RandomId::PATTERN, 'step' => RandomId::PATTERN])->group(function () {
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

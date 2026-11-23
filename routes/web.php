<?php

use App\Http\Controllers\Agents\ConnectAgentController;
use App\Http\Controllers\Agents\ConnectionLinkController;
use App\Http\Controllers\DownloadContractPlan;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// OAuth consent for MCP clients (Passport's GET /oauth/authorize renders oauth.authorize).
Route::post('oauth/akord/approve', [ConnectAgentController::class, 'approve'])
    ->middleware(['auth'])
    ->name('agents.connect.approve');

// Public: a one-time connection link an owner gives an agent. GET shows the agent what to do;
// POST (no CSRF: the agent calls it, not a browser) uses up the link and returns the MCP config.
Route::get('connect/{token}', [ConnectionLinkController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:agent-claim')
    ->name('agents.link.show');
Route::post('connect/{token}', [ConnectionLinkController::class, 'claim'])
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:agent-claim')
    ->withoutMiddleware(PreventRequestForgery::class)
    ->name('agents.link.claim');

// Public: the link in an invitation email. Guests are sent to log in or register and come back.
Route::livewire('invitations/{token}', 'pages::invitations.show')->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::organisations.index')->name('dashboard');
    Route::livewire('notifications', 'pages::notifications.index')->name('notifications.index');

    Route::livewire('orgs/{organisation:slug}', 'pages::organisations.show')->name('organisations.show');
    Route::livewire('orgs/{organisation:slug}/parties', 'pages::organisations.parties')->name('organisations.parties');
    Route::livewire('orgs/{organisation:slug}/parties/{party}/agent', 'pages::organisations.agent')->name('organisations.agent');
    Route::livewire('orgs/{organisation:slug}/projects/{project}', 'pages::projects.show')
        ->scopeBindings()
        ->name('projects.show');

    Route::livewire('contracts/{contract}', 'pages::contracts.show')->name('contracts.show');
    Route::livewire('contracts/{contract}/settings', 'pages::contracts.settings')->name('contracts.settings');
    Route::get('contracts/{contract}/plan.md', DownloadContractPlan::class)->name('contracts.plan');
});

require __DIR__.'/settings.php';

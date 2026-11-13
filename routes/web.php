<?php

use App\Http\Controllers\Agents\ConnectAgentController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// OAuth consent for MCP clients (Passport's GET /oauth/authorize renders oauth.authorize).
Route::post('oauth/akord/approve', [ConnectAgentController::class, 'approve'])
    ->middleware(['auth'])
    ->name('agents.connect.approve');

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
    Route::livewire('contracts/{contract}/steps/{step}', 'pages::steps.show')
        ->scopeBindings()
        ->name('steps.show');
});

require __DIR__.'/settings.php';

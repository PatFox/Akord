<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Public: the link in an invitation email. Guests are sent to log in or register and come back.
Route::livewire('invitations/{token}', 'pages::invitations.show')->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::organisations.index')->name('dashboard');

    Route::livewire('orgs/{organisation:slug}', 'pages::organisations.show')->name('organisations.show');
    Route::livewire('orgs/{organisation:slug}/parties', 'pages::organisations.parties')->name('organisations.parties');
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

<?php

use App\Models\Invitation;
use App\Models\User;

test('user and invitation emails are stored trimmed and lowercased', function () {
    $user = User::factory()->create(['email' => '  Alice@Example.COM ']);
    $invitation = Invitation::factory()->create(['email' => 'Bob@Example.com']);

    expect($user->fresh()?->email)->toBe('alice@example.com')
        ->and($invitation->fresh()?->email)->toBe('bob@example.com');
});

test('registering an email that differs only in case from an existing one is rejected', function () {
    User::factory()->create(['email' => 'alice@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Alice Again',
        'email' => 'ALICE@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->count())->toBe(1);
});

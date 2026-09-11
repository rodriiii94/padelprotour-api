<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

test('registering creates an unverified account, sends a verification email and does not return a token', function () {
    Notification::fake();

    $response = postJson('/api/register', [
        'name' => 'Ana',
        'email' => 'ana@example.test',
        'password' => 'password123',
    ])->assertCreated();

    expect($response->json())->not->toHaveKey('token');
    $this->assertDatabaseHas('users', ['email' => 'ana@example.test', 'email_verified_at' => null]);

    $user = User::where('email', 'ana@example.test')->first();
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

test('registration still succeeds even if the mail provider fails to send', function () {
    Notification::shouldReceive('send')->once()->andThrow(new Exception('mail provider down'));

    postJson('/api/register', [
        'name' => 'Ana',
        'email' => 'ana-mail-fail@example.test',
        'password' => 'password123',
    ])->assertCreated();

    $this->assertDatabaseHas('users', ['email' => 'ana-mail-fail@example.test']);
});

test('an unverified user cannot log in', function () {
    $user = User::factory()->create(['email_verified_at' => null, 'password' => Hash::make('password123')]);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertForbidden();
});

test('a verified user can log in and receives a token', function () {
    $user = User::factory()->create(['password' => Hash::make('password123')]);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertOk()->assertJsonStructure(['user', 'token']);
});

test('logging in with the wrong password on a social-only account fails cleanly', function () {
    $user = User::factory()->create(['password' => null, 'provider' => 'google', 'provider_id' => 'abc123']);

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'whatever',
        'device_name' => 'test',
    ])->assertUnprocessable();
});

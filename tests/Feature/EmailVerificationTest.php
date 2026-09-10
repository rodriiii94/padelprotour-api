<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

test('a valid token verifies the account and logs the user in', function () {
    $user = User::factory()->create([
        'email_verified_at' => null,
        'email_verification_token' => 'valid-token-123',
    ]);

    postJson('/api/email/verify', ['token' => 'valid-token-123', 'device_name' => 'test'])
        ->assertOk()
        ->assertJsonStructure(['user', 'token']);

    $user->refresh();
    expect($user->email_verified_at)->not->toBeNull()
        ->and($user->email_verification_token)->toBeNull();
});

test('an unknown or already used token is rejected', function () {
    postJson('/api/email/verify', ['token' => 'does-not-exist', 'device_name' => 'test'])
        ->assertNotFound();
});

test('resending does not reveal whether the email exists', function () {
    $response = postJson('/api/email/resend', ['email' => 'nobody@example.test'])->assertOk();

    $known = User::factory()->create(['email_verified_at' => null]);
    $responseForKnown = postJson('/api/email/resend', ['email' => $known->email])->assertOk();

    expect($response->json('message'))->toBe($responseForKnown->json('message'));
});

test('resending generates a fresh token and sends a new notification for an unverified account', function () {
    Notification::fake();
    $user = User::factory()->create(['email_verified_at' => null, 'email_verification_token' => 'old-token']);

    postJson('/api/email/resend', ['email' => $user->email])->assertOk();

    $user->refresh();
    expect($user->email_verification_token)->not->toBe('old-token');
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

test('resending does nothing for an already verified account', function () {
    Notification::fake();
    $user = User::factory()->create();

    postJson('/api/email/resend', ['email' => $user->email])->assertOk();

    Notification::assertNothingSent();
});

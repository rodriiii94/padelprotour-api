<?php

use App\Auth\SocialLogin\GoogleTokenVerifier;
use App\Auth\SocialLogin\InvalidSocialTokenException;
use App\Models\User;

use function Pest\Laravel\postJson;

test('login is rate limited after five failed attempts from the same email and ip', function () {
    $user = User::factory()->create();
    $payload = ['email' => $user->email, 'password' => 'wrong-password', 'device_name' => 'test'];

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/login', $payload)->assertUnprocessable();
    }

    postJson('/api/login', $payload)->assertStatus(429);
});

test('register is rate limited after five attempts from the same ip', function () {
    for ($i = 0; $i < 5; $i++) {
        postJson('/api/register', [
            'name' => 'Test',
            'email' => "test{$i}@example.com",
            'password' => 'password123',
            'device_name' => 'test',
        ])->assertCreated();
    }

    postJson('/api/register', [
        'name' => 'Test',
        'email' => 'test-overflow@example.com',
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertStatus(429);
});

test('social login is rate limited after ten attempts from the same ip', function () {
    $this->mock(GoogleTokenVerifier::class, fn ($mock) => $mock->shouldReceive('verify')
        ->times(10)
        ->andThrow(new InvalidSocialTokenException('bad token'))
    );

    for ($i = 0; $i < 10; $i++) {
        postJson('/api/auth/google', ['id_token' => 'fake', 'device_name' => 'test'])->assertUnprocessable();
    }

    postJson('/api/auth/google', ['id_token' => 'fake', 'device_name' => 'test'])->assertStatus(429);
});

test('email verification endpoints are rate limited after five attempts from the same ip', function () {
    for ($i = 0; $i < 5; $i++) {
        postJson('/api/email/verify', ['token' => 'nope', 'device_name' => 'test'])->assertNotFound();
    }

    postJson('/api/email/verify', ['token' => 'nope', 'device_name' => 'test'])->assertStatus(429);
});

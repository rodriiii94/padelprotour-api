<?php

use App\Auth\SocialLogin\GoogleTokenVerifier;
use App\Auth\SocialLogin\InvalidSocialTokenException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
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

test('login is also rate limited by email alone, even across different ips', function () {
    $user = User::factory()->create();
    $payload = ['email' => $user->email, 'password' => 'wrong-password', 'device_name' => 'test'];

    // Cada intento desde una IP distinta: el límite por email+IP nunca salta solo.
    for ($i = 0; $i < 15; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.{$i}.1"]);
        postJson('/api/login', $payload)->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.99.1']);
    postJson('/api/login', $payload)->assertStatus(429);
});

test('account deletion attempts are limited per account, not shared with other users behind the same ip', function () {
    $userA = User::factory()->create(['password' => Hash::make('secret-pass')]);
    $userB = User::factory()->create(['password' => Hash::make('secret-pass')]);

    Sanctum::actingAs($userA);
    for ($i = 0; $i < 5; $i++) {
        deleteJson('/api/me', ['password' => 'wrong'])->assertUnprocessable();
    }
    deleteJson('/api/me', ['password' => 'wrong'])->assertStatus(429);

    // Otra cuenta, aunque comparta IP en el test, conserva su propio cupo.
    Sanctum::actingAs($userB);
    deleteJson('/api/me', ['password' => 'wrong'])->assertUnprocessable();
});

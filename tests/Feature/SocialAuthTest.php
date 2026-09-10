<?php

use App\Auth\SocialLogin\AppleTokenVerifier;
use App\Auth\SocialLogin\GoogleTokenVerifier;
use App\Auth\SocialLogin\InvalidSocialTokenException;
use App\Models\User;

use function Pest\Laravel\postJson;

function mockGoogleClaims(array $overrides = []): void
{
    $claims = array_merge([
        'provider_id' => 'google-sub-123',
        'email' => 'player@example.test',
        'email_verified' => true,
        'name' => 'Ana Player',
    ], $overrides);

    test()->mock(GoogleTokenVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn($claims));
}

test('a new google user is created already verified and receives a token', function () {
    mockGoogleClaims();

    $response = postJson('/api/auth/google', ['id_token' => 'fake', 'device_name' => 'test'])
        ->assertOk()
        ->assertJsonStructure(['user', 'token']);

    expect($response->json('user.email'))->toBe('player@example.test');

    $user = User::where('email', 'player@example.test')->first();
    expect($user->provider)->toBe('google')
        ->and($user->provider_id)->toBe('google-sub-123')
        ->and($user->password)->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull();
});

test('logging in again with the same google account reuses the same user', function () {
    mockGoogleClaims();
    postJson('/api/auth/google', ['id_token' => 'fake', 'device_name' => 'device-1'])->assertOk();

    mockGoogleClaims();
    postJson('/api/auth/google', ['id_token' => 'fake', 'device_name' => 'device-2'])->assertOk();

    expect(User::where('provider', 'google')->where('provider_id', 'google-sub-123')->count())->toBe(1);
});

test('a google login conflicting with an existing account email is rejected', function () {
    User::factory()->create(['email' => 'player@example.test']);
    mockGoogleClaims();

    postJson('/api/auth/google', ['id_token' => 'fake', 'device_name' => 'test'])->assertStatus(409);
});

test('an invalid google token is rejected', function () {
    $this->mock(GoogleTokenVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andThrow(new InvalidSocialTokenException('Token inválido o caducado.')));

    postJson('/api/auth/google', ['id_token' => 'fake', 'device_name' => 'test'])->assertUnprocessable();
});

test('apple login accepts an optional name for the first-time-only claim', function () {
    $this->mock(AppleTokenVerifier::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn([
        'provider_id' => 'apple-sub-456',
        'email' => 'apple.player@example.test',
        'email_verified' => true,
        'name' => null,
    ]));

    postJson('/api/auth/apple', [
        'id_token' => 'fake',
        'device_name' => 'test',
        'name' => 'Marta Apple',
    ])->assertOk();

    expect(User::where('provider', 'apple')->first()->name)->toBe('Marta Apple');
});

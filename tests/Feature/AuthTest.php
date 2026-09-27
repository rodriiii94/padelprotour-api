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

test('logging in with a non existent email still runs a password check, so timing cannot reveal which emails exist', function () {
    Hash::shouldReceive('check')->once()->andReturn(false);

    postJson('/api/login', [
        'email' => 'nobody-registered@example.test',
        'password' => 'whatever',
        'device_name' => 'test',
    ])->assertUnprocessable();
});

test('login rejects an absurdly long email or password before touching the database', function () {
    postJson('/api/login', [
        'email' => str_repeat('a', 250).'@example.test',
        'password' => 'whatever',
        'device_name' => 'test',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    postJson('/api/login', [
        'email' => 'someone@example.test',
        'password' => str_repeat('a', 300),
        'device_name' => 'test',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

test('registration rejects a password known to be in a public data breach', function () {
    // Password real de prueba de Have I Been Pwned; solo se comprueba un prefijo de su
    // hash (k-anonimato), nunca la contraseña en sí -- ver NotPwnedVerifier.
    $leaked = 'TotallyLeakedTestPassword123';
    $hash = strtoupper(sha1($leaked));
    [$prefix, $suffix] = [substr($hash, 0, 5), substr($hash, 5)];

    // El servicio real solo devuelve el sufijo del hash que coincide con el prefijo
    // consultado, pero como el fake responde igual sea cual sea la URL exacta, basta con
    // que el cuerpo contenga la línea "sufijo:recuento" que NotPwnedVerifier busca.
    self::$pwnedPasswordsResponseBody = "{$suffix}:999\n";

    postJson('/api/register', ['name' => 'Ana', 'email' => 'ana-leaked@example.test', 'password' => $leaked])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->assertDatabaseMissing('users', ['email' => 'ana-leaked@example.test']);
});

test('registration rejects an absurdly long email or password', function () {
    postJson('/api/register', [
        'name' => 'Ana', 'email' => str_repeat('a', 250).'@example.test', 'password' => 'password123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    postJson('/api/register', [
        'name' => 'Ana', 'email' => 'ana2@example.test', 'password' => str_repeat('a', 300),
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

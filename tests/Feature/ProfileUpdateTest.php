<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

test('guests cannot update a profile', function () {
    putJson('/api/me', ['name' => 'Nuevo Nombre'])->assertUnauthorized();
});

test('a user can update their own name, level and club', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    Sanctum::actingAs($user);

    putJson('/api/me', ['name' => 'New Name', 'level' => '3ª', 'club' => 'Club Pádel Sur'])
        ->assertOk()
        ->assertJsonFragment(['name' => 'New Name', 'level' => '3ª', 'club' => 'Club Pádel Sur']);

    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name', 'level' => '3ª']);
});

test('email and password cannot be changed through the profile endpoint', function () {
    $user = User::factory()->create(['email' => 'original@example.test']);
    Sanctum::actingAs($user);

    putJson('/api/me', ['email' => 'hijacked@example.test', 'password' => 'newpassword123'])->assertOk();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'original@example.test']);
});

test('the first name change is free and starts the 30-day lock', function () {
    $user = User::factory()->create(['name' => 'Nombre de Google']);
    Sanctum::actingAs($user);

    putJson('/api/me', ['name' => 'Rodri'])
        ->assertOk()
        ->assertJsonFragment(['name' => 'Rodri'])
        ->assertJsonPath('name_change_available_at', fn ($value) => is_string($value));

    expect($user->refresh()->name_changed_at)->not->toBeNull();
});

test('a second name change within 30 days is rejected and says when it will be allowed', function () {
    $user = User::factory()->create(['name' => 'Rodri', 'name_changed_at' => now()->subDays(10)]);
    Sanctum::actingAs($user);

    $response = putJson('/api/me', ['name' => 'Otro Nombre'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect($response->json('errors.name.0'))->toContain(now()->addDays(20)->format('d/m/Y'));
    expect($user->refresh()->name)->toBe('Rodri');
});

test('the name can be changed again once 30 days have passed', function () {
    $user = User::factory()->create(['name' => 'Rodri', 'name_changed_at' => now()->subDays(31)]);
    Sanctum::actingAs($user);

    putJson('/api/me', ['name' => 'Otro Nombre'])->assertOk()->assertJsonFragment(['name' => 'Otro Nombre']);
});

test('the lock is measured from the last change, not from account creation', function () {
    $user = User::factory()->create(['name' => 'Rodri']);
    Sanctum::actingAs($user);

    $start = now();
    putJson('/api/me', ['name' => 'Primero'])->assertOk();

    $this->travelTo($start->copy()->addDays(29));
    putJson('/api/me', ['name' => 'Segundo'])->assertUnprocessable();

    $this->travelTo($start->copy()->addDays(31));
    putJson('/api/me', ['name' => 'Segundo'])->assertOk();
});

test('sending the same name again does not count as a change', function () {
    $lockedSince = now()->subDays(5)->startOfSecond();
    $user = User::factory()->create(['name' => 'Rodri', 'name_changed_at' => $lockedSince]);
    Sanctum::actingAs($user);

    putJson('/api/me', ['name' => 'Rodri', 'bio' => 'Juego los jueves'])->assertOk();

    $user->refresh();
    expect($user->bio)->toBe('Juego los jueves')
        ->and($user->name_changed_at->equalTo($lockedSince))->toBeTrue();
});

test('the other profile fields can be changed at any time, even while the name is locked', function () {
    $user = User::factory()->create(['name_changed_at' => now()->subDay()]);
    Sanctum::actingAs($user);

    putJson('/api/me', [
        'bio' => 'Drive de toda la vida',
        'city' => 'Sevilla',
        'preferred_side' => 'right',
        'dominant_hand' => 'left',
        'avatar_color' => 'lime',
        'avatar_emoji' => '🎾',
        'racket' => 'Bullpadel Vertex',
        'motto' => 'Una más',
        'availability' => ['mon-evening', 'sat-morning', 'mon-evening'],
    ])->assertOk()->assertJsonFragment(['city' => 'Sevilla', 'avatar_emoji' => '🎾']);

    expect($user->refresh()->availability)->toBe(['mon-evening', 'sat-morning']);
});

test('social links are stored as handles without the leading @ and empty ones are dropped', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    putJson('/api/me', ['social_links' => ['instagram' => '@rodri.padel', 'tiktok' => null, 'x' => 'rodri_x']])
        ->assertOk();

    expect($user->refresh()->social_links)->toBe(['instagram' => 'rodri.padel', 'x' => 'rodri_x']);

    putJson('/api/me', ['social_links' => ['instagram' => null]])->assertOk();
    expect($user->refresh()->social_links)->toBeNull();
});

test('invalid profile values are rejected', function (array $payload, string $field) {
    Sanctum::actingAs(User::factory()->create());

    putJson('/api/me', $payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'name too short' => [['name' => 'A'], 'name'],
    'name too long' => [['name' => str_repeat('a', 41)], 'name'],
    'unknown preferred side' => [['preferred_side' => 'middle'], 'preferred_side'],
    'unknown hand' => [['dominant_hand' => 'both'], 'dominant_hand'],
    'unknown avatar colour' => [['avatar_color' => 'pink'], 'avatar_color'],
    'letters as emoji' => [['avatar_emoji' => 'abc'], 'avatar_emoji'],
    'bio too long' => [['bio' => str_repeat('a', 201)], 'bio'],
    'unknown availability slot' => [['availability' => ['mon-night']], 'availability.0'],
    'unknown social network' => [['social_links' => ['myspace' => 'rodri']], 'social_links'],
    'a URL instead of a handle' => [['social_links' => ['instagram' => 'https://evil.example/x']], 'social_links.instagram'],
    'a handle with spaces' => [['social_links' => ['tiktok' => 'con espacios']], 'social_links.tiktok'],
]);

test('me and login both expose when the name can be changed again', function () {
    $user = User::factory()->create(['name_changed_at' => now()->subDays(3)]);
    Sanctum::actingAs($user);

    getJson('/api/me')->assertOk()->assertJsonPath('name_change_available_at', fn ($value) => is_string($value));
});

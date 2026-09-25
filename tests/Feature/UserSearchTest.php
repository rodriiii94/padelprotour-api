<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

test('guests cannot search users', function () {
    getJson('/api/users?search=ana')->assertUnauthorized();
});

test('a search term is required', function () {
    Sanctum::actingAs(User::factory()->create());

    getJson('/api/users')->assertUnprocessable();
    getJson('/api/users?search=a')->assertUnprocessable();
});

test('users can be found by partial name, ignoring case', function () {
    Sanctum::actingAs(User::factory()->create());

    $anaMartinez = User::factory()->create(['name' => 'Ana Martínez']);
    $juanAnaya = User::factory()->create(['name' => 'Juan Anaya']);
    User::factory()->create(['name' => 'Pedro Gómez']);

    $ids = collect(getJson('/api/users?search=ana')->assertOk()->json())->pluck('id');

    expect($ids->all())->toContain($anaMartinez->id)->toContain($juanAnaya->id)->toHaveCount(2);
});

test('the search cannot be used to find people by email', function () {
    Sanctum::actingAs(User::factory()->create());
    User::factory()->create(['name' => 'Ana Martínez', 'email' => 'ana.secreta@example.test']);

    getJson('/api/users?search=ana.secreta')->assertOk()->assertExactJson([]);
});

test('search results only carry public fields, never the email', function () {
    Sanctum::actingAs(User::factory()->create());
    User::factory()->create([
        'name' => 'Ana Martínez',
        'email' => 'ana.martinez@example.test',
        'provider' => 'google',
        'provider_id' => 'abc123',
        'city' => 'Sevilla',
    ]);

    $result = getJson('/api/users?search=Ana')->assertOk()->json('0');

    expect(array_keys($result))->toEqualCanonicalizing(User::SEARCH_COLUMNS)
        ->and($result['city'])->toBe('Sevilla');
});

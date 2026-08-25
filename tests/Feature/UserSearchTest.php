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

test('users can be found by partial name or email', function () {
    Sanctum::actingAs(User::factory()->create());

    $anaMartinez = User::factory()->create(['name' => 'Ana Martínez', 'email' => 'ana.martinez@example.test']);
    $juanAnaya = User::factory()->create(['name' => 'Juan Anaya', 'email' => 'juan@example.test']);
    User::factory()->create(['name' => 'Pedro Gómez', 'email' => 'pedro@example.test']);

    $byName = collect(getJson('/api/users?search=Ana')->assertOk()->json())->pluck('id');
    expect($byName)->toContain($anaMartinez->id)->toContain($juanAnaya->id);

    $byEmail = collect(getJson('/api/users?search=ana.martinez')->assertOk()->json())->pluck('id');
    expect($byEmail->all())->toBe([$anaMartinez->id]);
});

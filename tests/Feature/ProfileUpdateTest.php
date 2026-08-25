<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

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

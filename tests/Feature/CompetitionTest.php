<?php

use App\Models\Competition;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('guests cannot access competitions', function () {
    getJson('/api/competitions')->assertUnauthorized();
});

test('an authenticated user can create a competition as its organizer', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/competitions', [
        'type' => 'tournament',
        'name' => 'Torneo de Verano',
        'start_date' => now()->addWeek()->toDateString(),
    ]);

    $response->assertCreated();
    expect($response->json('organizer_id'))->toBe($user->id);
    $this->assertDatabaseHas('competitions', [
        'name' => 'Torneo de Verano',
        'organizer_id' => $user->id,
    ]);
});

test('creating a competition requires the mandatory fields', function () {
    Sanctum::actingAs(User::factory()->create());

    postJson('/api/competitions', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'name', 'start_date']);
});

test('any authenticated user can list and view competitions', function () {
    Sanctum::actingAs(User::factory()->create());
    $competition = Competition::factory()->create();

    getJson('/api/competitions')->assertOk();
    getJson("/api/competitions/{$competition->id}")->assertOk();
});

test('the organizer can update their competition', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    Sanctum::actingAs($organizer);

    putJson("/api/competitions/{$competition->id}", ['name' => 'Nuevo nombre'])
        ->assertOk()
        ->assertJsonFragment(['name' => 'Nuevo nombre']);
});

test('a user who is not the organizer cannot update the competition', function () {
    $competition = Competition::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    putJson("/api/competitions/{$competition->id}", ['name' => 'Nuevo nombre'])
        ->assertForbidden();
});

test('the organizer can delete their competition', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    Sanctum::actingAs($organizer);

    deleteJson("/api/competitions/{$competition->id}")->assertNoContent();
    $this->assertDatabaseMissing('competitions', ['id' => $competition->id]);
});

test('a user who is not the organizer cannot delete the competition', function () {
    $competition = Competition::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    deleteJson("/api/competitions/{$competition->id}")->assertForbidden();
    $this->assertDatabaseHas('competitions', ['id' => $competition->id]);
});

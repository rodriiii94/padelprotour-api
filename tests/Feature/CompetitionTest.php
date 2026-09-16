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

test('the upcoming filter excludes competitions that already finished', function () {
    Sanctum::actingAs(User::factory()->create());
    $finished = Competition::factory()->create(['end_date' => now()->subDay()->toDateString()]);
    $ongoing = Competition::factory()->create(['end_date' => null]);
    $future = Competition::factory()->create(['end_date' => now()->addWeek()->toDateString()]);

    $ids = collect(getJson('/api/competitions?upcoming=1')->assertOk()->json('data'))->pluck('id');

    expect($ids)->not->toContain($finished->id)
        ->toContain($ongoing->id)
        ->toContain($future->id);
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

test('the organizer can cancel their competition and it stops appearing in the public listing', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    Sanctum::actingAs($organizer);

    postJson("/api/competitions/{$competition->id}/cancel")
        ->assertOk()
        ->assertJsonPath('cancelled_at', fn ($value) => $value !== null);

    $ids = collect(getJson('/api/competitions')->assertOk()->json('data'))->pluck('id');
    expect($ids)->not->toContain($competition->id);
});

test('a user who is not the organizer cannot cancel the competition', function () {
    $competition = Competition::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/competitions/{$competition->id}/cancel")->assertForbidden();
});

test('a competition cannot be cancelled twice', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'cancelled_at' => now()]);
    Sanctum::actingAs($organizer);

    postJson("/api/competitions/{$competition->id}/cancel")->assertUnprocessable();
});

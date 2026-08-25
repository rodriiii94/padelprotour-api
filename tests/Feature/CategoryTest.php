<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('the organizer can add a category to their competition', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    Sanctum::actingAs($organizer);

    $response = postJson("/api/competitions/{$competition->id}/categories", [
        'name' => '3ª Mixta',
        'slots' => 16,
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('categories', [
        'competition_id' => $competition->id,
        'name' => '3ª Mixta',
    ]);
});

test('a user who is not the organizer cannot add a category', function () {
    $competition = Competition::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/competitions/{$competition->id}/categories", ['name' => '3ª Mixta'])
        ->assertForbidden();
});

test('a tournament category cannot use individual rotation', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create([
        'organizer_id' => $organizer->id,
        'type' => 'tournament',
    ]);
    Sanctum::actingAs($organizer);

    postJson("/api/competitions/{$competition->id}/categories", [
        'name' => '3ª Mixta',
        'registration_mode' => 'individual_rotating',
    ])->assertUnprocessable()->assertJsonValidationErrors(['registration_mode']);
});

test('a league category can use individual rotation', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create([
        'organizer_id' => $organizer->id,
        'type' => 'league',
    ]);
    Sanctum::actingAs($organizer);

    postJson("/api/competitions/{$competition->id}/categories", [
        'name' => '3ª Mixta',
        'registration_mode' => 'individual_rotating',
    ])->assertCreated();
});

test('the organizer can update and delete a category', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    Sanctum::actingAs($organizer);

    putJson("/api/categories/{$category->id}", ['name' => 'Nuevo nombre'])
        ->assertOk()
        ->assertJsonFragment(['name' => 'Nuevo nombre']);

    deleteJson("/api/categories/{$category->id}")->assertNoContent();
    $this->assertDatabaseMissing('categories', ['id' => $category->id]);
});

test('a user who is not the organizer cannot update or delete a category', function () {
    $competition = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    Sanctum::actingAs(User::factory()->create());

    putJson("/api/categories/{$category->id}", ['name' => 'Nuevo nombre'])->assertForbidden();
    deleteJson("/api/categories/{$category->id}")->assertForbidden();
});

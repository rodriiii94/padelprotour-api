<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\Phase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('guests cannot access phases', function () {
    $category = Category::factory()->create();

    getJson("/api/categories/{$category->id}/phases")->assertUnauthorized();
});

test('the organizer can create a phase', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/phases", [
        'type' => 'elimination_round',
        'name' => 'Cuartos de final',
        'order' => 1,
    ])->assertCreated()->assertJsonFragment(['name' => 'Cuartos de final']);
});

test('a non organizer cannot create a phase', function () {
    $category = Category::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/categories/{$category->id}/phases", [
        'type' => 'elimination_round',
        'name' => 'Cuartos de final',
        'order' => 1,
    ])->assertForbidden();
});

test('the organizer can update and delete a phase', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    Sanctum::actingAs($organizer);

    putJson("/api/phases/{$phase->id}", ['name' => 'Semifinal'])
        ->assertOk()
        ->assertJsonFragment(['name' => 'Semifinal']);

    deleteJson("/api/phases/{$phase->id}")->assertNoContent();
});

test('a non organizer cannot update or delete a phase', function () {
    $competition = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    Sanctum::actingAs(User::factory()->create());

    putJson("/api/phases/{$phase->id}", ['name' => 'Semifinal'])->assertForbidden();
    deleteJson("/api/phases/{$phase->id}")->assertForbidden();
});

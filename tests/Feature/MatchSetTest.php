<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\MatchSet;
use App\Models\PadelMatch;
use App\Models\Phase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('guests cannot access match sets', function () {
    $match = PadelMatch::factory()->create();

    getJson("/api/matches/{$match->id}/sets")->assertUnauthorized();
});

test('the organizer can record a set', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 1,
        'side1_games' => 6,
        'side2_games' => 4,
    ])->assertCreated()->assertJsonFragment(['side1_games' => 6, 'side2_games' => 4]);
});

test('recording a duplicate set number is rejected', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    MatchSet::factory()->create(['match_id' => $match->id, 'set_number' => 1]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 1,
        'side1_games' => 6,
        'side2_games' => 2,
    ])->assertUnprocessable();
});

test('a non organizer cannot record a set', function () {
    $match = PadelMatch::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 1,
        'side1_games' => 6,
        'side2_games' => 4,
    ])->assertForbidden();
});

test('the organizer can correct and delete a set', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    $set = MatchSet::factory()->create(['match_id' => $match->id]);
    Sanctum::actingAs($organizer);

    putJson("/api/sets/{$set->id}", ['side1_games' => 7])
        ->assertOk()
        ->assertJsonFragment(['side1_games' => 7]);

    deleteJson("/api/sets/{$set->id}")->assertNoContent();
});

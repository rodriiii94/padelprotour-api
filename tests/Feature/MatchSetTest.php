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
    $set = MatchSet::factory()->create(['match_id' => $match->id, 'side1_games' => 4, 'side2_games' => 6]);
    Sanctum::actingAs($organizer);

    putJson("/api/sets/{$set->id}", ['side1_games' => 7, 'side2_games' => 5])
        ->assertOk()
        ->assertJsonFragment(['side1_games' => 7, 'side2_games' => 5]);

    deleteJson("/api/sets/{$set->id}")->assertNoContent();
});

test('a set number beyond 3 is rejected', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 4,
        'side1_games' => 6,
        'side2_games' => 4,
    ])->assertUnprocessable()->assertJsonValidationErrors(['set_number']);
});

test('a fourth set is rejected once a side has already won two', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    MatchSet::factory()->create(['match_id' => $match->id, 'set_number' => 1, 'side1_games' => 6, 'side2_games' => 2]);
    MatchSet::factory()->create(['match_id' => $match->id, 'set_number' => 2, 'side1_games' => 6, 'side2_games' => 3]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 3,
        'side1_games' => 6,
        'side2_games' => 4,
    ])->assertUnprocessable();
});

test('a third set is allowed when each side has won one', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    MatchSet::factory()->create(['match_id' => $match->id, 'set_number' => 1, 'side1_games' => 6, 'side2_games' => 2]);
    MatchSet::factory()->create(['match_id' => $match->id, 'set_number' => 2, 'side1_games' => 3, 'side2_games' => 6]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 3,
        'side1_games' => 7,
        'side2_games' => 6,
    ])->assertCreated();
});

test('invalid padel set scores are rejected', function (int $side1, int $side2) {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 1,
        'side1_games' => $side1,
        'side2_games' => $side2,
    ])->assertUnprocessable();
})->with([
    'nobody wins with 9 games' => [9, 0],
    'a 0-7 blowout does not exist in padel' => [0, 7],
    'a set cannot end tied' => [5, 5],
    '6-5 is not over yet, must continue to 7-5 or a tie-break' => [6, 5],
]);

test('valid padel set scores are accepted', function (int $side1, int $side2) {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", [
        'set_number' => 1,
        'side1_games' => $side1,
        'side2_games' => $side2,
    ])->assertCreated();
})->with([
    'standard set' => [6, 4],
    'extended to seven' => [7, 5],
    'tie-break' => [7, 6],
]);

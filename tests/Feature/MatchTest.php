<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Phase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('guests cannot access matches', function () {
    $phase = Phase::factory()->create();

    getJson("/api/phases/{$phase->id}/matches")->assertUnauthorized();
});

test('the organizer can schedule a match with four distinct players', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $players = User::factory()->count(4)->create();
    Sanctum::actingAs($organizer);

    postJson("/api/phases/{$phase->id}/matches", [
        'side1_player1_id' => $players[0]->id,
        'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id,
        'side2_player2_id' => $players[3]->id,
    ])->assertCreated()->assertJsonFragment(['status' => 'scheduled']);
});

test('scheduling a match with a repeated player is rejected', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $players = User::factory()->count(3)->create();
    Sanctum::actingAs($organizer);

    postJson("/api/phases/{$phase->id}/matches", [
        'side1_player1_id' => $players[0]->id,
        'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id,
        'side2_player2_id' => $players[0]->id,
    ])->assertUnprocessable()->assertJsonValidationErrors(['side2_player2_id']);
});

test('a non organizer cannot schedule a match', function () {
    $phase = Phase::factory()->create();
    $players = User::factory()->count(4)->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/phases/{$phase->id}/matches", [
        'side1_player1_id' => $players[0]->id,
        'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id,
        'side2_player2_id' => $players[3]->id,
    ])->assertForbidden();
});

test('completing a match requires a winner side', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs($organizer);

    putJson("/api/matches/{$match->id}", ['status' => 'completed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['winner_side']);

    putJson("/api/matches/{$match->id}", ['status' => 'completed', 'winner_side' => 1])
        ->assertOk()
        ->assertJsonFragment(['status' => 'completed', 'winner_side' => 1]);
});

test('viewing a match embeds all four players', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $players = User::factory()->count(4)->create();
    $match = PadelMatch::factory()->create([
        'phase_id' => $phase->id,
        'side1_player1_id' => $players[0]->id,
        'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id,
        'side2_player2_id' => $players[3]->id,
    ]);
    Sanctum::actingAs($organizer);

    $json = getJson("/api/matches/{$match->id}")->assertOk()->json();

    expect($json['side1_player1']['id'])->toBe($players[0]->id)
        ->and($json['side1_player2']['id'])->toBe($players[1]->id)
        ->and($json['side2_player1']['id'])->toBe($players[2]->id)
        ->and($json['side2_player2']['id'])->toBe($players[3]->id);
});

test('a non organizer cannot update or delete a match', function () {
    $competition = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs(User::factory()->create());

    putJson("/api/matches/{$match->id}", ['court' => 'Pista 2'])->assertForbidden();
    deleteJson("/api/matches/{$match->id}")->assertForbidden();
});

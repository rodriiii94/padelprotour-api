<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Phase;
use App\Models\Ranking;
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

test('a match can only be completed once a side has won two sets, and the winner comes from the sets', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs($organizer);

    putJson("/api/matches/{$match->id}", ['status' => 'completed', 'winner_side' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $match->matchSets()->create(['set_number' => 1, 'side1_games' => 2, 'side2_games' => 6]);
    putJson("/api/matches/{$match->id}", ['status' => 'completed', 'winner_side' => 2])
        ->assertUnprocessable()->assertJsonValidationErrors(['status']);

    $match->matchSets()->create(['set_number' => 2, 'side1_games' => 4, 'side2_games' => 6]);
    putJson("/api/matches/{$match->id}", ['status' => 'completed', 'winner_side' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['winner_side']);

    putJson("/api/matches/{$match->id}", ['status' => 'completed'])
        ->assertOk()
        ->assertJsonFragment(['status' => 'completed', 'winner_side' => 2]);
});

test('an organizer who plays the match cannot record its result directly', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $match = PadelMatch::factory()->create(['phase_id' => $phase->id, 'side1_player1_id' => $organizer->id]);
    $set = $match->matchSets()->create(['set_number' => 1, 'side1_games' => 6, 'side2_games' => 2]);
    Sanctum::actingAs($organizer);

    postJson("/api/matches/{$match->id}/sets", ['set_number' => 2, 'side1_games' => 6, 'side2_games' => 3])
        ->assertForbidden()
        ->assertJsonFragment(['message' => 'Juegas este partido: propón el resultado y que lo confirme un rival.']);
    putJson("/api/sets/{$set->id}", ['side1_games' => 6, 'side2_games' => 0])->assertForbidden();
    putJson("/api/matches/{$match->id}", ['status' => 'completed'])->assertForbidden();

    putJson("/api/matches/{$match->id}", ['court' => 'Pista 4'])->assertOk();

    expect($match->fresh())->status->toBe('scheduled')->court->toBe('Pista 4')
        ->and($match->matchSets()->count())->toBe(1);
});

test('viewing a match embeds all four players with their public profile, avatar included', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $players = User::factory()->count(4)->create();
    $players[0]->forceFill(['avatar_color' => 'lime', 'avatar_emoji' => '🎾'])->save();
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
        ->and($json['side1_player1']['avatar_color'])->toBe('lime')
        ->and($json['side1_player1']['avatar_emoji'])->toBe('🎾')
        ->and($json['side1_player1'])->toHaveKey('avatar_url')
        ->and($json['side1_player2']['id'])->toBe($players[1]->id)
        ->and($json['side2_player1']['id'])->toBe($players[2]->id)
        ->and($json['side2_player2']['id'])->toBe($players[3]->id);
});

test('listing a phase\'s matches also embeds the players\' avatar', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $players = User::factory()->count(4)->create();
    PadelMatch::factory()->create([
        'phase_id' => $phase->id,
        'side1_player1_id' => $players[0]->id,
        'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id,
        'side2_player2_id' => $players[3]->id,
    ]);
    Sanctum::actingAs($organizer);

    $json = getJson("/api/phases/{$phase->id}/matches")->assertOk()->json();

    expect($json['data'][0]['side1_player1'])->toHaveKeys(['id', 'name', 'avatar_url', 'avatar_color', 'avatar_emoji']);
});

test('completing a match recalculates the category ranking automatically', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $group = Phase::factory()->create(['category_id' => $category->id, 'type' => 'group']);
    $pairA = Pair::factory()->create();
    $pairB = Pair::factory()->create();
    $match = PadelMatch::factory()->create([
        'phase_id' => $group->id,
        'side1_player1_id' => $pairA->player1_id,
        'side1_player2_id' => $pairA->player2_id,
        'side2_player1_id' => $pairB->player1_id,
        'side2_player2_id' => $pairB->player2_id,
        'status' => 'scheduled',
    ]);
    Sanctum::actingAs($organizer);

    expect(Ranking::where('category_id', $category->id)->count())->toBe(0);

    $match->matchSets()->createMany([
        ['set_number' => 1, 'side1_games' => 6, 'side2_games' => 3],
        ['set_number' => 2, 'side1_games' => 6, 'side2_games' => 4],
    ]);
    putJson("/api/matches/{$match->id}", ['status' => 'completed', 'winner_side' => 1])->assertOk();

    $rankings = Ranking::where('category_id', $category->id)->orderBy('position')->get();
    expect($rankings)->toHaveCount(2)
        ->and($rankings[0]->pair_id)->toBe($pairA->id)
        ->and($rankings[0]->points)->toBe(3)
        ->and($rankings[1]->points)->toBe(0);
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

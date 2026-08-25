<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\MatchdayPairing;
use App\Models\Phase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

function matchdayPhase(?User $organizer = null): Phase
{
    $organizer ??= User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'type' => 'league']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);

    return Phase::factory()->create(['category_id' => $category->id, 'type' => 'matchday']);
}

test('guests cannot access matchday pairings', function () {
    $phase = matchdayPhase();

    getJson("/api/phases/{$phase->id}/matchday-pairings")->assertUnauthorized();
});

test('the organizer can pair two players for a matchday', function () {
    $organizer = User::factory()->create();
    $phase = matchdayPhase($organizer);
    $players = User::factory()->count(2)->create();
    Sanctum::actingAs($organizer);

    postJson("/api/phases/{$phase->id}/matchday-pairings", [
        'player1_id' => $players[0]->id,
        'player2_id' => $players[1]->id,
    ])->assertCreated()->assertJsonFragment(['player1_id' => $players[0]->id]);
});

test('a non organizer cannot create a matchday pairing', function () {
    $phase = matchdayPhase();
    $players = User::factory()->count(2)->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/phases/{$phase->id}/matchday-pairings", [
        'player1_id' => $players[0]->id,
        'player2_id' => $players[1]->id,
    ])->assertForbidden();
});

test('a player cannot be paired with themselves', function () {
    $organizer = User::factory()->create();
    $phase = matchdayPhase($organizer);
    $player = User::factory()->create();
    Sanctum::actingAs($organizer);

    postJson("/api/phases/{$phase->id}/matchday-pairings", [
        'player1_id' => $player->id,
        'player2_id' => $player->id,
    ])->assertUnprocessable();
});

test('a player already paired in the matchday cannot be paired again', function () {
    $organizer = User::factory()->create();
    $phase = matchdayPhase($organizer);
    $players = User::factory()->count(3)->create();
    MatchdayPairing::factory()->create([
        'phase_id' => $phase->id,
        'player1_id' => $players[0]->id,
        'player2_id' => $players[1]->id,
    ]);
    Sanctum::actingAs($organizer);

    // players[0] already has a partner this matchday, even as the "other side".
    postJson("/api/phases/{$phase->id}/matchday-pairings", [
        'player1_id' => $players[2]->id,
        'player2_id' => $players[0]->id,
    ])->assertUnprocessable();
});

test('pairings are rejected outside matchday phases or fixed pair categories', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $group = Phase::factory()->create(['category_id' => $category->id, 'type' => 'group']);
    $players = User::factory()->count(2)->create();
    Sanctum::actingAs($organizer);

    postJson("/api/phases/{$group->id}/matchday-pairings", [
        'player1_id' => $players[0]->id,
        'player2_id' => $players[1]->id,
    ])->assertUnprocessable();
});

test('the organizer can update and delete a matchday pairing', function () {
    $organizer = User::factory()->create();
    $phase = matchdayPhase($organizer);
    $pairing = MatchdayPairing::factory()->create(['phase_id' => $phase->id]);
    $newPartner = User::factory()->create();
    Sanctum::actingAs($organizer);

    putJson("/api/matchday-pairings/{$pairing->id}", ['player2_id' => $newPartner->id])
        ->assertOk()
        ->assertJsonFragment(['player2_id' => $newPartner->id]);

    deleteJson("/api/matchday-pairings/{$pairing->id}")->assertNoContent();
});

test('a non organizer cannot update or delete a matchday pairing', function () {
    $phase = matchdayPhase();
    $pairing = MatchdayPairing::factory()->create(['phase_id' => $phase->id]);
    Sanctum::actingAs(User::factory()->create());

    putJson("/api/matchday-pairings/{$pairing->id}", ['player1_id' => $pairing->player1_id])->assertForbidden();
    deleteJson("/api/matchday-pairings/{$pairing->id}")->assertForbidden();
});

<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Phase;
use App\Models\Registration;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function createCompletedMatch(Phase $phase, array $players, int $winnerSide, array $sets): PadelMatch
{
    $match = PadelMatch::factory()->create([
        'phase_id' => $phase->id,
        'side1_player1_id' => $players[0],
        'side1_player2_id' => $players[1],
        'side2_player1_id' => $players[2],
        'side2_player2_id' => $players[3],
        'status' => 'completed',
        'winner_side' => $winnerSide,
    ]);

    foreach ($sets as $number => [$side1Games, $side2Games]) {
        $match->matchSets()->create([
            'set_number' => $number + 1,
            'side1_games' => $side1Games,
            'side2_games' => $side2Games,
        ]);
    }

    return $match;
}

test('guests cannot access rankings', function () {
    $category = Category::factory()->create();

    getJson("/api/categories/{$category->id}/rankings")->assertUnauthorized();
});

test('recalculating ranking for a three way tie breaks by set difference', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $group = Phase::factory()->create(['category_id' => $category->id, 'type' => 'group']);

    $pairA = Pair::factory()->create();
    $pairB = Pair::factory()->create();
    $pairC = Pair::factory()->create();

    // A beats B 2-0
    createCompletedMatch($group, [$pairA->player1_id, $pairA->player2_id, $pairB->player1_id, $pairB->player2_id], 1, [
        [6, 2],
        [6, 3],
    ]);
    // C beats A 2-1 (A wins one set)
    createCompletedMatch($group, [$pairA->player1_id, $pairA->player2_id, $pairC->player1_id, $pairC->player2_id], 2, [
        [6, 2],
        [3, 6],
        [4, 6],
    ]);
    // B beats C 2-0
    createCompletedMatch($group, [$pairB->player1_id, $pairB->player2_id, $pairC->player1_id, $pairC->player2_id], 1, [
        [6, 1],
        [6, 0],
    ]);

    Sanctum::actingAs($organizer);

    $response = postJson("/api/categories/{$category->id}/rankings/recalculate")->assertOk();

    $ranked = collect($response->json())->keyBy('pair_id');

    expect($ranked[$pairA->id]['points'])->toBe(3)
        ->and($ranked[$pairB->id]['points'])->toBe(3)
        ->and($ranked[$pairC->id]['points'])->toBe(3)
        ->and($ranked[$pairA->id]['position'])->toBe(1)
        ->and($ranked[$pairB->id]['position'])->toBe(2)
        ->and($ranked[$pairC->id]['position'])->toBe(3);
});

test('elimination round matches do not count towards ranking points', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $group = Phase::factory()->create(['category_id' => $category->id, 'type' => 'group']);
    $knockout = Phase::factory()->create(['category_id' => $category->id, 'type' => 'elimination_round']);

    $pairA = Pair::factory()->create();
    $pairB = Pair::factory()->create();

    createCompletedMatch($group, [$pairA->player1_id, $pairA->player2_id, $pairB->player1_id, $pairB->player2_id], 1, [[6, 0], [6, 0]]);
    createCompletedMatch($knockout, [$pairB->player1_id, $pairB->player2_id, $pairA->player1_id, $pairA->player2_id], 1, [[6, 0], [6, 0]]);

    Sanctum::actingAs($organizer);

    $response = postJson("/api/categories/{$category->id}/rankings/recalculate")->assertOk();
    $ranked = collect($response->json())->keyBy('pair_id');

    expect($ranked[$pairA->id]['points'])->toBe(3)
        ->and($ranked[$pairB->id]['points'])->toBe(0);
});

test('pending validation matches do not count towards ranking points', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $group = Phase::factory()->create(['category_id' => $category->id, 'type' => 'group']);

    $pairA = Pair::factory()->create();
    $pairB = Pair::factory()->create();

    PadelMatch::factory()->create([
        'phase_id' => $group->id,
        'side1_player1_id' => $pairA->player1_id,
        'side1_player2_id' => $pairA->player2_id,
        'side2_player1_id' => $pairB->player1_id,
        'side2_player2_id' => $pairB->player2_id,
        'status' => 'pending_validation',
        'winner_side' => null,
    ]);

    Sanctum::actingAs($organizer);

    $response = postJson("/api/categories/{$category->id}/rankings/recalculate")->assertOk();

    expect($response->json())->toBe([]);
});

test('individual rotating categories rank by player instead of pair', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'type' => 'league']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    $matchday = Phase::factory()->create(['category_id' => $category->id, 'type' => 'matchday']);
    $players = User::factory()->count(4)->create();

    createCompletedMatch($matchday, $players->pluck('id')->all(), 1, [[6, 2], [6, 3]]);

    Sanctum::actingAs($organizer);

    $response = postJson("/api/categories/{$category->id}/rankings/recalculate")->assertOk();
    $ranked = collect($response->json())->keyBy('player_id');

    expect($ranked[$players[0]->id]['points'])->toBe(3)
        ->and($ranked[$players[1]->id]['points'])->toBe(3)
        ->and($ranked[$players[2]->id]['points'])->toBe(0)
        ->and($ranked[$players[3]->id]['points'])->toBe(0);
});

test('a non organizer cannot recalculate rankings', function () {
    $category = Category::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/categories/{$category->id}/rankings/recalculate")->assertForbidden();
});

test('each ranking row carries the full stats: matches, sets and games for and against', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $group = Phase::factory()->create(['category_id' => $category->id, 'type' => 'group']);
    $pairA = Pair::factory()->create();
    $pairB = Pair::factory()->create();

    createCompletedMatch($group, [$pairA->player1_id, $pairA->player2_id, $pairB->player1_id, $pairB->player2_id], 1, [[6, 2], [3, 6], [7, 5]]);
    createCompletedMatch($group, [$pairB->player1_id, $pairB->player2_id, $pairA->player1_id, $pairA->player2_id], 1, [[6, 4], [6, 4]]);

    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/rankings/recalculate")->assertOk();

    $ranked = collect(getJson("/api/categories/{$category->id}/rankings")->assertOk()->json())->keyBy('pair_id');

    expect($ranked[$pairA->id])->toMatchArray([
        'points' => 3, 'played' => 2, 'won' => 1, 'lost' => 1,
        'sets_won' => 2, 'sets_lost' => 3, 'games_won' => 24, 'games_lost' => 25,
    ])->and($ranked[$pairB->id])->toMatchArray([
        'points' => 3, 'played' => 2, 'won' => 1, 'lost' => 1,
        'sets_won' => 3, 'sets_lost' => 2, 'games_won' => 25, 'games_lost' => 24,
    ])->and($ranked[$pairB->id]['position'])->toBe(1);
});

test('confirmed registrations that have not played yet appear in the table with zeros', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $group = Phase::factory()->create(['category_id' => $category->id, 'type' => 'group']);
    [$pairA, $pairB, $idle, $pending] = Pair::factory()->count(4)->create();
    foreach ([$pairA, $pairB, $idle] as $pair) {
        Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $pair->id, 'status' => 'confirmed']);
    }
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $pending->id, 'status' => 'pending']);

    createCompletedMatch($group, [$pairA->player1_id, $pairA->player2_id, $pairB->player1_id, $pairB->player2_id], 2, [[2, 6], [4, 6]]);

    Sanctum::actingAs($organizer);
    $ranked = collect(postJson("/api/categories/{$category->id}/rankings/recalculate")->assertOk()->json());

    expect($ranked->pluck('pair_id')->all())->toBe([$pairB->id, $idle->id, $pairA->id])
        ->and($ranked->firstWhere('pair_id', $idle->id))->toMatchArray(['points' => 0, 'played' => 0, 'position' => 2]);
});

test('ranking rows embed the pair with its players\' names, never their emails', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $pair = Pair::factory()->create(['name' => 'Towers']);
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $pair->id, 'status' => 'confirmed']);

    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/rankings/recalculate")->assertOk();

    $row = getJson("/api/categories/{$category->id}/rankings")->assertOk()->json('0');

    expect($row['pair']['name'])->toBe('Towers')
        ->and($row['pair']['player1'])->toBe(['id' => $pair->player1_id, 'name' => $pair->player1->name])
        ->and($row['pair']['player2'])->toBe(['id' => $pair->player2_id, 'name' => $pair->player2->name])
        ->and(json_encode($row))->not->toContain($pair->player1->email);
});

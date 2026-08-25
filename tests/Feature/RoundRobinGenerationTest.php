<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Phase;
use App\Models\Registration;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

function confirmedPairRegistration(Category $category): Pair
{
    $pair = Pair::factory()->create();
    Registration::factory()->create([
        'category_id' => $category->id,
        'pair_id' => $pair->id,
        'player_id' => null,
        'status' => 'confirmed',
    ]);

    return $pair;
}

function leagueCategory(?User $organizer = null): Category
{
    $organizer ??= User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'type' => 'league']);

    return Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
}

test('guests cannot generate a round robin', function () {
    $category = leagueCategory();

    postJson("/api/categories/{$category->id}/round-robin")->assertUnauthorized();
});

test('a non organizer cannot generate a round robin', function () {
    $category = leagueCategory();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/categories/{$category->id}/round-robin")->assertForbidden();
});

test('tournament categories are rejected', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'type' => 'tournament']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    confirmedPairRegistration($category);
    confirmedPairRegistration($category);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertUnprocessable();
});

test('individual rotating categories are rejected', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'type' => 'league']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertUnprocessable();
});

test('fewer than two confirmed pairs is rejected', function () {
    $organizer = User::factory()->create();
    $category = leagueCategory($organizer);
    confirmedPairRegistration($category);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertUnprocessable();
});

test('generating a round robin for four pairs produces every matchup exactly once', function () {
    $organizer = User::factory()->create();
    $category = leagueCategory($organizer);
    $pairs = collect(range(1, 4))->map(fn () => confirmedPairRegistration($category));
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertCreated();

    expect(Phase::where('category_id', $category->id)->where('type', 'matchday')->count())->toBe(3);

    $matches = PadelMatch::whereIn('phase_id', Phase::where('category_id', $category->id)->pluck('id'))->get();
    expect($matches)->toHaveCount(6);

    $matchups = $matches->map(function (PadelMatch $match) use ($pairs) {
        $pairAId = $pairs->first(fn (Pair $pair) => $pair->player1_id === $match->side1_player1_id)->id;
        $pairBId = $pairs->first(fn (Pair $pair) => $pair->player1_id === $match->side2_player1_id)->id;

        return collect([$pairAId, $pairBId])->sort()->values()->implode('-');
    })->unique();

    expect($matchups)->toHaveCount(6);
});

test('an odd number of pairs gets an automatic bye each matchday', function () {
    $organizer = User::factory()->create();
    $category = leagueCategory($organizer);
    collect(range(1, 3))->each(fn () => confirmedPairRegistration($category));
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertCreated();

    $phases = Phase::where('category_id', $category->id)->where('type', 'matchday')->get();
    expect($phases)->toHaveCount(3);

    foreach ($phases as $phase) {
        expect($phase->matches()->count())->toBe(1);
    }
});

test('generating a round robin twice for the same category is rejected', function () {
    $organizer = User::factory()->create();
    $category = leagueCategory($organizer);
    confirmedPairRegistration($category);
    confirmedPairRegistration($category);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertCreated();
    postJson("/api/categories/{$category->id}/round-robin")->assertUnprocessable();
});

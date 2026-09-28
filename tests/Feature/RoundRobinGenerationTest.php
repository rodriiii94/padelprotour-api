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

test('the generated schedule embeds player names for each match', function () {
    $organizer = User::factory()->create();
    $category = leagueCategory($organizer);
    collect(range(1, 2))->each(fn () => confirmedPairRegistration($category));
    Sanctum::actingAs($organizer);

    $phases = postJson("/api/categories/{$category->id}/round-robin")->assertCreated()->json();

    expect($phases[0]['matches'][0]['side1_player1'])->toHaveKeys(['id', 'name', 'avatar_url', 'avatar_color', 'avatar_emoji'])
        ->and($phases[0]['matches'][0]['side2_player2'])->toHaveKeys(['id', 'name', 'avatar_url', 'avatar_color', 'avatar_emoji']);
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

test('a double round league plays every matchup twice, with sides swapped on the return leg', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'type' => 'league', 'double_round' => true]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $pairs = collect(range(1, 4))->map(fn () => confirmedPairRegistration($category));
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertCreated();

    // 3 jornadas de ida + 3 de vuelta.
    expect(Phase::where('category_id', $category->id)->where('type', 'matchday')->count())->toBe(6);

    $matches = PadelMatch::whereIn('phase_id', Phase::where('category_id', $category->id)->pluck('id'))->get();
    expect($matches)->toHaveCount(12);

    $matchup = fn (PadelMatch $match) => collect([
        $pairs->first(fn (Pair $pair) => $pair->player1_id === $match->side1_player1_id)->id,
        $pairs->first(fn (Pair $pair) => $pair->player1_id === $match->side2_player1_id)->id,
    ])->sort()->values()->implode('-');

    // Cada cruce (sin ordenar por lado) aparece exactamente dos veces: ida y vuelta.
    expect($matches->map($matchup)->countBy())->each->toBe(2);

    // En la vuelta, quien jugó de lado 1 en la ida pasa a jugar de lado 2.
    $idaSide1 = PadelMatch::whereIn('phase_id', Phase::where('category_id', $category->id)->where('order', '<=', 3)->pluck('id'))
        ->pluck('side1_player1_id')->sort()->values();
    $vueltaSide2 = PadelMatch::whereIn('phase_id', Phase::where('category_id', $category->id)->where('order', '>', 3)->pluck('id'))
        ->pluck('side2_player1_id')->sort()->values();
    expect($idaSide1->all())->toBe($vueltaSide2->all());
});

test('a single round league (double_round false, the default) only plays every matchup once', function () {
    $organizer = User::factory()->create();
    $category = leagueCategory($organizer);
    collect(range(1, 4))->each(fn () => confirmedPairRegistration($category));
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/round-robin")->assertCreated();

    expect(Phase::where('category_id', $category->id)->where('type', 'matchday')->count())->toBe(3);
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

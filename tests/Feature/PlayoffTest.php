<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Phase;
use App\Models\Registration;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * Liga de pareja fija ya jugada en la que cada pareja gana a todas las que tiene detrás:
 * la clasificación final queda en el orden de `$pairs` (la primera, líder).
 *
 * @return array{organizer: User, category: Category, pairs: list<Pair>}
 */
function finishedLeague(int $pairCount): array
{
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'type' => 'league']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $matchday = Phase::factory()->create(['category_id' => $category->id, 'type' => 'matchday', 'order' => 1]);
    $pairs = Pair::factory()->count($pairCount)->create()->all();

    foreach ($pairs as $pair) {
        Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $pair->id, 'status' => 'confirmed']);
    }

    foreach ($pairs as $i => $better) {
        foreach (array_slice($pairs, $i + 1) as $worse) {
            $match = PadelMatch::factory()->create([
                'phase_id' => $matchday->id, 'status' => 'completed', 'winner_side' => 1,
                'side1_player1_id' => $better->player1_id, 'side1_player2_id' => $better->player2_id,
                'side2_player1_id' => $worse->player1_id, 'side2_player2_id' => $worse->player2_id,
            ]);
            $match->matchSets()->createMany([
                ['set_number' => 1, 'side1_games' => 6, 'side2_games' => 3],
                ['set_number' => 2, 'side1_games' => 6, 'side2_games' => 4],
            ]);
        }
    }

    return compact('organizer', 'category', 'pairs');
}

/** Ids de las dos parejas de un partido, [lado 1, lado 2]. */
function pairsOf(PadelMatch $match): array
{
    return [
        Pair::query()->where('player1_id', $match->side1_player1_id)->where('player2_id', $match->side1_player2_id)->value('id'),
        Pair::query()->where('player1_id', $match->side2_player1_id)->where('player2_id', $match->side2_player2_id)->value('id'),
    ];
}

/** Un jugador del lado 1 propone que gana su lado y un rival lo confirma. */
function side1WinsByProposal(PadelMatch $match): void
{
    Sanctum::actingAs(User::find($match->side1_player1_id));
    postJson("/api/matches/{$match->id}/result-proposal", [
        'sets' => [['side1_games' => 6, 'side2_games' => 2], ['side1_games' => 6, 'side2_games' => 1]],
    ])->assertCreated();

    Sanctum::actingAs(User::find($match->side2_player1_id));
    postJson("/api/matches/{$match->id}/result-proposal/confirm")->assertOk();
}

test('the organizer creates a 4-pair playoff seeded from the final standings', function () {
    ['organizer' => $organizer, 'category' => $category, 'pairs' => $pairs] = finishedLeague(5);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();

    $phases = $category->phases()->where('type', 'elimination_round')->orderBy('order')->get();
    expect($phases->pluck('name')->all())->toBe(['Semifinales', 'Final'])
        ->and($phases->pluck('order')->all())->toBe([2, 3]);

    $semis = $phases[0]->matches()->orderBy('bracket_position')->get();
    expect($semis)->toHaveCount(2)
        ->and(pairsOf($semis[0]))->toBe([$pairs[0]->id, $pairs[3]->id])
        ->and(pairsOf($semis[1]))->toBe([$pairs[1]->id, $pairs[2]->id])
        ->and($semis->pluck('status')->unique()->all())->toBe(['scheduled'])
        ->and($phases[1]->matches()->count())->toBe(0);
});

test('an 8-pair playoff keeps the top two seeds apart until the final', function () {
    ['organizer' => $organizer, 'category' => $category, 'pairs' => $pairs] = finishedLeague(8);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/playoff", ['size' => 8])->assertCreated();

    $phases = $category->phases()->where('type', 'elimination_round')->orderBy('order')->get();
    $quarters = $phases[0]->matches()->orderBy('bracket_position')->get()->map(fn ($m) => pairsOf($m))->all();
    $seed = fn (int $n) => $pairs[$n - 1]->id;

    expect($phases->pluck('name')->all())->toBe(['Cuartos de final', 'Semifinales', 'Final'])
        ->and($quarters)->toBe([[$seed(1), $seed(8)], [$seed(4), $seed(5)], [$seed(2), $seed(7)], [$seed(3), $seed(6)]]);
});

test('creating a playoff is refused when it does not make sense, including before the league is over', function () {
    ['organizer' => $organizer, 'category' => $category, 'pairs' => $pairs] = finishedLeague(3);

    Sanctum::actingAs(User::factory()->create());
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertForbidden();

    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 6])->assertUnprocessable()->assertJsonValidationErrors('size');
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('size');

    $extra = Pair::factory()->create();
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $extra->id, 'status' => 'confirmed']);
    $pending = PadelMatch::factory()->create([
        'phase_id' => $category->phases()->first()->id, 'status' => 'pending_validation', 'winner_side' => 1,
        'side1_player1_id' => $pairs[0]->player1_id, 'side1_player2_id' => $pairs[0]->player2_id,
        'side2_player1_id' => $extra->player1_id, 'side2_player2_id' => $extra->player2_id,
    ]);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('playoff');

    $pending->update(['status' => 'scheduled', 'winner_side' => null]);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])
        ->assertUnprocessable()
        ->assertJsonPath('errors.playoff.0', 'La liga no ha terminado: queda 1 partido sin resultado confirmado.');

    $pending->update(['status' => 'completed', 'winner_side' => 1]);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('playoff');
});

test('a playoff is only for fixed-pair categories', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    Sanctum::actingAs($organizer);

    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('playoff');
});

test('winners advance to the final once both semifinals are confirmed, best seed on side 1', function () {
    ['organizer' => $organizer, 'category' => $category, 'pairs' => $pairs] = finishedLeague(4);
    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();
    [$semis, $final] = $category->phases()->where('type', 'elimination_round')->orderBy('order')->get();
    [$semi1, $semi2] = $semis->matches()->orderBy('bracket_position')->get();

    side1WinsByProposal($semi2);
    expect($final->matches()->count())->toBe(0);

    side1WinsByProposal($semi1);
    $finalMatch = $final->matches()->sole();
    expect($finalMatch->bracket_position)->toBe(0)
        ->and($finalMatch->status)->toBe('scheduled')
        ->and(pairsOf($finalMatch))->toBe([$pairs[0]->id, $pairs[1]->id]);
});

test('results confirmed automatically after 48 hours also advance the winner', function () {
    ['organizer' => $organizer, 'category' => $category, 'pairs' => $pairs] = finishedLeague(4);
    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();
    [$semis, $final] = $category->phases()->where('type', 'elimination_round')->orderBy('order')->get();
    [$semi1, $semi2] = $semis->matches()->orderBy('bracket_position')->get();

    side1WinsByProposal($semi1);
    Sanctum::actingAs(User::find($semi2->side2_player1_id));
    postJson("/api/matches/{$semi2->id}/result-proposal", [
        'sets' => [['side1_games' => 2, 'side2_games' => 6], ['side1_games' => 3, 'side2_games' => 6]],
    ])->assertCreated();

    $this->travel(49)->hours();
    $this->artisan('matches:auto-confirm-results')->assertSuccessful();

    expect(pairsOf($final->matches()->sole()))->toBe([$pairs[0]->id, $pairs[2]->id]);
});

test('a playoff result cannot be changed once its winner has advanced', function () {
    ['organizer' => $organizer, 'category' => $category] = finishedLeague(4);
    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();
    [$semi1, $semi2] = $category->phases()->where('type', 'elimination_round')->orderBy('order')->first()
        ->matches()->orderBy('bracket_position')->get();
    side1WinsByProposal($semi1);
    side1WinsByProposal($semi2);

    Sanctum::actingAs($organizer);
    putJson("/api/matches/{$semi1->id}", ['status' => 'scheduled'])
        ->assertForbidden()
        ->assertJsonFragment(['message' => 'El ganador ya ha pasado a la siguiente ronda: no se puede cambiar este resultado.']);
});

test('the bracket shows every round, placeholders for undecided slots and the champion', function () {
    ['organizer' => $organizer, 'category' => $category, 'pairs' => $pairs] = finishedLeague(4);
    $pairs[1]->update(['name' => 'Towers']);
    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();
    [$semi1, $semi2] = $category->phases()->where('type', 'elimination_round')->orderBy('order')->first()
        ->matches()->orderBy('bracket_position')->get();
    side1WinsByProposal($semi1);

    $bracket = getJson("/api/categories/{$category->id}/playoff")->assertOk()->json();

    expect($bracket['size'])->toBe(4)
        ->and($bracket['champion'])->toBeNull()
        ->and(collect($bracket['rounds'])->pluck('name')->all())->toBe(['Semifinales', 'Final'])
        ->and($bracket['rounds'][0]['matches'][0])->toMatchArray(['position' => 0, 'match_id' => $semi1->id, 'status' => 'completed', 'winner_side' => 1, 'score' => '6-2 6-1'])
        ->and($bracket['rounds'][0]['matches'][0]['sides'][0])->toMatchArray(['seed' => 1, 'pair_id' => $pairs[0]->id])
        ->and($bracket['rounds'][0]['matches'][1]['sides'][0])->toMatchArray(['seed' => 2, 'name' => 'Towers'])
        ->and($bracket['rounds'][1]['matches'][0])->toMatchArray(['position' => 0, 'match_id' => null])
        ->and($bracket['rounds'][1]['matches'][0]['sides'][0])->toMatchArray(['seed' => 1, 'pair_id' => $pairs[0]->id])
        ->and($bracket['rounds'][1]['matches'][0]['sides'][1])->toBe(['placeholder' => 'Ganador de Semifinal 2']);

    side1WinsByProposal($semi2);
    $final = $category->phases()->where('type', 'elimination_round')->where('name', 'Final')->sole()->matches()->sole();
    side1WinsByProposal($final);

    Sanctum::actingAs($organizer);
    expect(getJson("/api/categories/{$category->id}/playoff")->json('champion'))
        ->toMatchArray(['pair_id' => $pairs[0]->id]);
});

test('a category without a playoff returns 404 on the bracket', function () {
    ['organizer' => $organizer, 'category' => $category] = finishedLeague(4);
    Sanctum::actingAs($organizer);

    getJson("/api/categories/{$category->id}/playoff")->assertNotFound();
});

test('the organizer can remove a playoff until a result is entered', function () {
    ['organizer' => $organizer, 'category' => $category] = finishedLeague(4);
    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();

    deleteJson("/api/categories/{$category->id}/playoff")->assertNoContent();
    expect($category->phases()->where('type', 'elimination_round')->count())->toBe(0)
        ->and($category->phases()->count())->toBe(1);

    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();
    $semi = $category->phases()->where('type', 'elimination_round')->orderBy('order')->first()->matches()->first();
    side1WinsByProposal($semi);

    Sanctum::actingAs($organizer);
    deleteJson("/api/categories/{$category->id}/playoff")->assertUnprocessable()->assertJsonValidationErrors('playoff');
});

test('the playoff winner is the champion, not the league leader', function () {
    ['organizer' => $organizer, 'category' => $category, 'pairs' => $pairs] = finishedLeague(4);
    $category->competition->update(['end_date' => now()->subDay()]);
    Sanctum::actingAs($organizer);
    postJson("/api/categories/{$category->id}/playoff", ['size' => 4])->assertCreated();
    [$semi1, $semi2] = $category->phases()->where('type', 'elimination_round')->orderBy('order')->first()
        ->matches()->orderBy('bracket_position')->get();
    side1WinsByProposal($semi1);
    side1WinsByProposal($semi2);
    $final = $category->phases()->where('type', 'elimination_round')->where('name', 'Final')->sole()->matches()->sole();

    // Gana la final el lado 2 (la segunda de la liga).
    Sanctum::actingAs(User::find($final->side2_player1_id));
    postJson("/api/matches/{$final->id}/result-proposal", [
        'sets' => [['side1_games' => 4, 'side2_games' => 6], ['side1_games' => 5, 'side2_games' => 7]],
    ])->assertCreated();
    Sanctum::actingAs(User::find($final->side1_player1_id));
    postJson("/api/matches/{$final->id}/result-proposal/confirm")->assertOk();

    expect(getJson("/api/users/{$pairs[1]->player1_id}")->json('achievements'))->toContain('champion')
        ->and(getJson("/api/users/{$pairs[0]->player1_id}")->json('achievements'))->not->toContain('champion');
});

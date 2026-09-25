<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\MatchSet;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Ranking;
use App\Models\Registration;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

/**
 * Partido terminado en el que $player juega con $mate contra dos rivales
 * nuevos. $winnerSide y los sets se dan desde el lado 1.
 *
 * @param  list<array{int, int}>  $sets  [juegos lado 1, juegos lado 2]
 */
function completedMatch(User $player, User $mate, int $playerSide, int $winnerSide, array $sets, ?DateTimeInterface $at = null): PadelMatch
{
    [$rival1, $rival2] = User::factory()->count(2)->create();
    $team = [$player->id, $mate->id];
    $rivals = [$rival1->id, $rival2->id];
    [$side1, $side2] = $playerSide === 1 ? [$team, $rivals] : [$rivals, $team];

    $match = PadelMatch::factory()->create([
        'side1_player1_id' => $side1[0],
        'side1_player2_id' => $side1[1],
        'side2_player1_id' => $side2[0],
        'side2_player2_id' => $side2[1],
        'status' => 'completed',
        'winner_side' => $winnerSide,
        'scheduled_at' => $at ?? now()->subDay(),
    ]);

    foreach ($sets as $index => [$games1, $games2]) {
        MatchSet::factory()->create([
            'match_id' => $match->id,
            'set_number' => $index + 1,
            'side1_games' => $games1,
            'side2_games' => $games2,
        ]);
    }

    return $match;
}

test('guests cannot view a player profile', function () {
    $player = User::factory()->create();

    getJson("/api/users/{$player->id}")->assertUnauthorized();
});

test('an unknown player is not found', function () {
    Sanctum::actingAs(User::factory()->create());

    getJson('/api/users/999999')->assertNotFound();
});

test('any user can view another player profile, without email or login data', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create([
        'email' => 'secreto@example.test',
        'provider' => 'google',
        'provider_id' => 'abc123',
        'name' => 'Ana Martínez',
        'bio' => 'Juego los jueves',
        'city' => 'Sevilla',
        'preferred_side' => 'left',
        'dominant_hand' => 'right',
        'avatar_color' => 'sky',
        'avatar_emoji' => '🎾',
        'racket' => 'Nox AT10',
        'motto' => 'Una más',
        'availability' => ['thu-evening'],
        'social_links' => ['instagram' => 'ana.padel'],
    ]);

    $json = getJson("/api/users/{$player->id}")->assertOk()->json();

    expect($json)
        ->toMatchArray([
            'id' => $player->id,
            'name' => 'Ana Martínez',
            'bio' => 'Juego los jueves',
            'city' => 'Sevilla',
            'preferred_side' => 'left',
            'dominant_hand' => 'right',
            'avatar_color' => 'sky',
            'avatar_emoji' => '🎾',
            'racket' => 'Nox AT10',
            'motto' => 'Una más',
            'availability' => ['thu-evening'],
            'social_links' => ['instagram' => 'ana.padel'],
        ])
        ->not->toHaveKeys(['email', 'provider', 'provider_id', 'email_verified_at', 'password', 'name_changed_at']);

    expect(json_encode($json))->not->toContain('secreto@example.test');
});

test('a player with no matches has empty stats', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();

    getJson("/api/users/{$player->id}")
        ->assertOk()
        ->assertJsonPath('stats.matches_played', 0)
        ->assertJsonPath('stats.win_rate', null)
        ->assertJsonPath('stats.current_streak', 0)
        ->assertJsonPath('achievements', [])
        ->assertJsonPath('usual_partner', null);
});

test('stats are counted from the player point of view and ignore matches that are not completed', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();
    $mate = User::factory()->create();

    // Gana en el lado 1 (6-3, 6-4) y pierde en el lado 2 (6-2, 6-1 para el lado 1).
    completedMatch($player, $mate, 1, 1, [[6, 3], [6, 4]], now()->subDays(2));
    completedMatch($player, $mate, 2, 1, [[6, 2], [6, 1]], now()->subDay());
    PadelMatch::factory()->create(['side1_player1_id' => $player->id, 'status' => 'scheduled']);

    $stats = getJson("/api/users/{$player->id}")->assertOk()->json('stats');

    expect($stats)->toMatchArray([
        'matches_played' => 2,
        'wins' => 1,
        'losses' => 1,
        'win_rate' => 50,
        'current_streak' => 0,
        'best_streak' => 1,
        'sets_won' => 2,
        'sets_lost' => 2,
        'games_won' => 15,
        'games_lost' => 19,
    ]);
});

test('the first win unlocks an achievement and a repeated teammate becomes the usual partner', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();
    $mate = User::factory()->create(['name' => 'Marta']);

    completedMatch($player, $mate, 1, 1, [[6, 3], [6, 3]]);
    completedMatch($player, $mate, 1, 1, [[6, 4], [6, 4]]);
    completedMatch($player, User::factory()->create(), 1, 2, [[3, 6], [3, 6]]);

    $json = getJson("/api/users/{$player->id}")->assertOk()->json();

    expect($json['achievements'])->toContain('first_win')->not->toContain('matches_10')
        ->and($json['usual_partner'])->toBe(['id' => $mate->id, 'name' => 'Marta', 'matches' => 2]);
});

test('a single match together is not enough to have a usual partner', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();

    completedMatch($player, User::factory()->create(), 1, 1, [[6, 0], [6, 0]]);

    getJson("/api/users/{$player->id}")->assertOk()->assertJsonPath('usual_partner', null);
});

test('five wins in a row unlock the streak achievement even if the streak later ends', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();
    $mate = User::factory()->create();

    foreach (range(6, 2) as $daysAgo) {
        completedMatch($player, $mate, 1, 1, [[6, 2], [6, 2]], now()->subDays($daysAgo));
    }
    completedMatch($player, $mate, 1, 2, [[2, 6], [2, 6]], now()->subDay());

    $json = getJson("/api/users/{$player->id}")->assertOk()->json();

    expect($json['achievements'])->toContain('win_streak_5')
        ->and($json['stats']['current_streak'])->toBe(0)
        ->and($json['stats']['best_streak'])->toBe(5);
});

test('playing ten matches unlocks its achievement', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();
    $mate = User::factory()->create();

    foreach (range(1, 10) as $i) {
        completedMatch($player, $mate, 1, 2, [[2, 6], [2, 6]]);
    }

    $json = getJson("/api/users/{$player->id}")->assertOk()->json();

    expect($json['achievements'])->toContain('matches_10')->not->toContain('first_win');
});

test('finishing first in a category of a finished competition makes you a champion', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $player->id]);

    $finished = Competition::factory()->create(['end_date' => now()->subWeek()]);
    Ranking::factory()->create([
        'category_id' => Category::factory()->create(['competition_id' => $finished->id])->id,
        'pair_id' => $pair->id,
        'position' => 1,
    ]);

    getJson("/api/users/{$player->id}")->assertOk()->assertJsonPath('achievements', ['champion']);
});

test('being first in an ongoing or cancelled competition does not make you a champion', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $player->id]);

    $ongoing = Competition::factory()->create(['end_date' => now()->addWeek()]);
    $cancelled = Competition::factory()->create(['end_date' => now()->subWeek(), 'cancelled_at' => now()->subWeeks(2)]);
    foreach ([$ongoing, $cancelled] as $competition) {
        Ranking::factory()->create([
            'category_id' => Category::factory()->create(['competition_id' => $competition->id])->id,
            'pair_id' => $pair->id,
            'position' => 1,
        ]);
    }

    getJson("/api/users/{$player->id}")->assertOk()->assertJsonPath('achievements', []);
});

test('competitions played counts each competition once and ignores rejected registrations', function () {
    Sanctum::actingAs(User::factory()->create());
    $player = User::factory()->create();
    $pair = Pair::factory()->create(['player2_id' => $player->id]);

    $competition = Competition::factory()->create();
    foreach ([Category::factory()->create(['competition_id' => $competition->id]), Category::factory()->create(['competition_id' => $competition->id])] as $category) {
        Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $pair->id, 'status' => 'confirmed']);
    }
    Registration::factory()->create(['category_id' => Category::factory()->create()->id, 'pair_id' => $pair->id, 'status' => 'rejected']);

    getJson("/api/users/{$player->id}")->assertOk()->assertJsonPath('stats.competitions_played', 1);
});

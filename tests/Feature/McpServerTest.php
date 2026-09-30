<?php

use App\Mcp\Servers\PadelProTourServer;
use App\Mcp\Tools\GetCompetitionTool;
use App\Mcp\Tools\GetStandingsTool;
use App\Mcp\Tools\ListMyCompetitionsTool;
use App\Mcp\Tools\ListMyMatchesTool;
use App\Mcp\Tools\ProposeMatchResultTool;
use App\Mcp\Tools\UpdateMatchBookingTool;
use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Phase;
use App\Models\Ranking;
use App\Models\User;

use function Pest\Laravel\postJson;

/**
 * @return array{competition: Competition, category: Category, match: PadelMatch, organizer: User, players: list<User>}
 */
function mcpLeague(): array
{
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'name' => 'Liga Otoño', 'is_private' => true]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'name' => 'Mixta A']);
    $phase = Phase::factory()->create(['category_id' => $category->id]);
    $players = User::factory()->count(4)->create()->all();

    $match = PadelMatch::factory()->create([
        'phase_id' => $phase->id,
        'side1_player1_id' => $players[0]->id, 'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id, 'side2_player2_id' => $players[3]->id,
    ]);

    return compact('competition', 'category', 'match', 'organizer', 'players');
}

test('the MCP endpoint requires a sanctum token and lists the tools with one', function () {
    postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

    $token = User::factory()->create()->createToken('claude')->plainTextToken;

    $tools = postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => "Bearer {$token}"])
        ->assertOk()
        ->json('result.tools.*.name');

    expect($tools)->toEqualCanonicalizing([
        'list_my_competitions', 'get_competition', 'get_standings',
        'list_my_matches', 'propose_match_result', 'update_match_booking',
    ]);
});

test('list_my_competitions only returns competitions I organize or play in', function () {
    ['players' => $players, 'organizer' => $organizer] = mcpLeague();
    Competition::factory()->create(['name' => 'Torneo Ajeno']);

    PadelProTourServer::actingAs($organizer)->tool(ListMyCompetitionsTool::class)
        ->assertOk()->assertSee(['Liga Otoño', '"i_am_organizer":true'])->assertDontSee('Torneo Ajeno');

    PadelProTourServer::actingAs(User::factory()->create())->tool(ListMyCompetitionsTool::class)
        ->assertOk()->assertDontSee('Liga Otoño');
});

test('get_competition hides private competitions from outsiders', function () {
    ['competition' => $competition, 'organizer' => $organizer] = mcpLeague();

    PadelProTourServer::actingAs($organizer)->tool(GetCompetitionTool::class, ['competition_id' => $competition->id])
        ->assertOk()->assertSee(['Liga Otoño', 'Mixta A', $organizer->name]);

    PadelProTourServer::actingAs(User::factory()->create())->tool(GetCompetitionTool::class, ['competition_id' => $competition->id])
        ->assertHasErrors(['No existe esa competición o no tienes acceso a ella.']);
});

test('get_standings shows positions and pair names but never emails', function () {
    ['category' => $category, 'organizer' => $organizer, 'players' => $players] = mcpLeague();
    $pair = Pair::factory()->create(['player1_id' => $players[0]->id, 'player2_id' => $players[1]->id, 'name' => 'Los Cracks']);
    Ranking::factory()->create(['category_id' => $category->id, 'pair_id' => $pair->id, 'player_id' => null, 'position' => 1, 'points' => 9]);

    PadelProTourServer::actingAs($organizer)->tool(GetStandingsTool::class, ['category_id' => $category->id])
        ->assertOk()->assertSee(['Los Cracks', '"points":9'])->assertDontSee($players[0]->email);

    PadelProTourServer::actingAs(User::factory()->create())->tool(GetStandingsTool::class, ['category_id' => $category->id])
        ->assertHasErrors();
});

test('list_my_matches returns my upcoming matches with rivals and booking', function () {
    ['match' => $match, 'players' => $players] = mcpLeague();
    $match->update(['club' => 'Club Pádel Norte', 'playtomic_url' => 'https://app.playtomic.com/t/abc']);
    PadelMatch::factory()->create();

    PadelProTourServer::actingAs($players[2])->tool(ListMyMatchesTool::class)
        ->assertOk()
        ->assertSee([$players[0]->name, 'Club Pádel Norte', 'https://app.playtomic.com/t/abc', '"my_side":2'])
        ->assertDontSee($players[0]->email);

    PadelProTourServer::actingAs($players[2])->tool(ListMyMatchesTool::class, ['filter' => 'completed'])
        ->assertOk()->assertDontSee('Club Pádel Norte');
});

test('propose_match_result lets a player propose and leaves it pending', function () {
    ['match' => $match, 'players' => $players] = mcpLeague();

    PadelProTourServer::actingAs($players[0])->tool(ProposeMatchResultTool::class, [
        'match_id' => $match->id,
        'sets' => [['side1_games' => 6, 'side2_games' => 3], ['side1_games' => 7, 'side2_games' => 5]],
    ])->assertOk()->assertSee(['pending_validation', '6-3', '7-5']);

    expect($match->fresh())->status->toBe('pending_validation')->winner_side->toBe(1);
});

test('propose_match_result rejects outsiders and impossible scores', function () {
    ['match' => $match, 'players' => $players, 'organizer' => $organizer] = mcpLeague();
    $sets = [['side1_games' => 6, 'side2_games' => 3], ['side1_games' => 6, 'side2_games' => 4]];

    PadelProTourServer::actingAs($organizer)->tool(ProposeMatchResultTool::class, ['match_id' => $match->id, 'sets' => $sets])
        ->assertHasErrors(['Solo los jugadores del partido pueden proponer el resultado.']);

    PadelProTourServer::actingAs(User::factory()->create())->tool(ProposeMatchResultTool::class, ['match_id' => $match->id, 'sets' => $sets])
        ->assertHasErrors(['No existe ese partido o no tienes acceso a él.']);

    PadelProTourServer::actingAs($players[0])->tool(ProposeMatchResultTool::class, [
        'match_id' => $match->id,
        'sets' => [['side1_games' => 6, 'side2_games' => 5], ['side1_games' => 6, 'side2_games' => 0]],
    ])->assertHasErrors();

    expect($match->fresh()->status)->toBe('scheduled');
});

test('update_match_booking saves only the fields sent and validates the playtomic link', function () {
    ['match' => $match, 'players' => $players] = mcpLeague();
    $match->update(['court' => 'Pista 1']);

    PadelProTourServer::actingAs($players[3])->tool(UpdateMatchBookingTool::class, [
        'match_id' => $match->id,
        'club' => 'Club Sur',
        'scheduled_at' => '2026-10-04T19:30:00+02:00',
    ])->assertOk()->assertSee(['Club Sur', 'Pista 1', '2026-10-04T17:30:00+00:00']);

    PadelProTourServer::actingAs($players[3])->tool(UpdateMatchBookingTool::class, [
        'match_id' => $match->id,
        'playtomic_url' => 'https://evil.com/t/1',
    ])->assertHasErrors(['El enlace debe ser un enlace de Playtomic.']);

    expect($match->fresh())->club->toBe('Club Sur')->playtomic_url->toBeNull();
});

test('update_match_booking is forbidden to someone outside the match', function () {
    ['match' => $match, 'competition' => $competition] = mcpLeague();
    $competition->update(['is_private' => false]);

    PadelProTourServer::actingAs(User::factory()->create())->tool(UpdateMatchBookingTool::class, [
        'match_id' => $match->id,
        'club' => 'Intruso',
    ])->assertHasErrors();

    expect($match->fresh()->club)->toBeNull();
});

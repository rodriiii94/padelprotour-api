<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

/**
 * Partido programado con cuatro jugadores en una competición con organizador.
 *
 * @return array{match: PadelMatch, organizer: User, a1: User, a2: User, b1: User, b2: User}
 */
function scheduledMatch(): array
{
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $phase = Phase::factory()->create(['category_id' => Category::factory()->create(['competition_id' => $competition->id])->id]);
    [$a1, $a2, $b1, $b2] = User::factory()->count(4)->create();

    $match = PadelMatch::factory()->create([
        'phase_id' => $phase->id,
        'side1_player1_id' => $a1->id, 'side1_player2_id' => $a2->id,
        'side2_player1_id' => $b1->id, 'side2_player2_id' => $b2->id,
        'status' => 'scheduled', 'winner_side' => null,
    ]);

    return compact('match', 'organizer', 'a1', 'a2', 'b1', 'b2');
}

function wonInTwoSets(): array
{
    return ['sets' => [['side1_games' => 6, 'side2_games' => 3], ['side1_games' => 6, 'side2_games' => 4]]];
}

test('a player proposes a result and the match waits for validation', function () {
    ['match' => $match, 'a1' => $a1] = scheduledMatch();
    Sanctum::actingAs($a1);

    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())
        ->assertCreated()
        ->assertJsonPath('status', 'pending_validation')
        ->assertJsonPath('winner_side', 1)
        ->assertJsonPath('result_proposed_by', $a1->id)
        ->assertJsonCount(2, 'match_sets');
});

test('only players of the match can propose, and never twice', function () {
    ['match' => $match, 'a1' => $a1, 'organizer' => $organizer] = scheduledMatch();

    Sanctum::actingAs(User::factory()->create());
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertForbidden();

    Sanctum::actingAs($organizer);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertForbidden();

    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertUnprocessable();
});

test('an invalid or undecided result is rejected', function () {
    ['match' => $match, 'a1' => $a1] = scheduledMatch();
    Sanctum::actingAs($a1);

    // marcador imposible
    postJson("/api/matches/{$match->id}/result-proposal", ['sets' => [['side1_games' => 6, 'side2_games' => 5], ['side1_games' => 6, 'side2_games' => 0]]])
        ->assertUnprocessable()->assertJsonValidationErrors('sets');
    // 1-1 en sets: falta el tercero
    postJson("/api/matches/{$match->id}/result-proposal", ['sets' => [['side1_games' => 6, 'side2_games' => 3], ['side1_games' => 3, 'side2_games' => 6]]])
        ->assertUnprocessable()->assertJsonValidationErrors('sets');
    // tercer set cuando ya estaba decidido
    postJson("/api/matches/{$match->id}/result-proposal", ['sets' => [
        ['side1_games' => 6, 'side2_games' => 3], ['side1_games' => 6, 'side2_games' => 4], ['side1_games' => 6, 'side2_games' => 0],
    ]])->assertUnprocessable()->assertJsonValidationErrors('sets');

    expect($match->fresh()->status)->toBe('scheduled');
    expect($match->matchSets()->count())->toBe(0);
});

test('a rival confirms the result and it becomes official', function () {
    ['match' => $match, 'a1' => $a1, 'b1' => $b1] = scheduledMatch();
    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();

    Sanctum::actingAs($b1);
    postJson("/api/matches/{$match->id}/result-proposal/confirm")
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('winner_side', 1);
});

test('the proposer and their partner cannot confirm their own result, but the organizer can', function () {
    ['match' => $match, 'a1' => $a1, 'a2' => $a2, 'organizer' => $organizer] = scheduledMatch();
    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();

    postJson("/api/matches/{$match->id}/result-proposal/confirm")->assertForbidden();
    Sanctum::actingAs($a2);
    postJson("/api/matches/{$match->id}/result-proposal/confirm")->assertForbidden();
    Sanctum::actingAs(User::factory()->create());
    postJson("/api/matches/{$match->id}/result-proposal/confirm")->assertForbidden();

    Sanctum::actingAs($organizer);
    postJson("/api/matches/{$match->id}/result-proposal/confirm")->assertOk()->assertJsonPath('status', 'completed');
});

test('a rival rejecting the result clears it, and the proposer can withdraw it', function () {
    ['match' => $match, 'a1' => $a1, 'b1' => $b1] = scheduledMatch();

    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();
    Sanctum::actingAs($b1);
    postJson("/api/matches/{$match->id}/result-proposal/reject")
        ->assertOk()->assertJsonPath('status', 'scheduled')->assertJsonPath('winner_side', null);
    expect($match->matchSets()->count())->toBe(0);

    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();
    postJson("/api/matches/{$match->id}/result-proposal/reject")->assertOk()->assertJsonPath('status', 'scheduled');
});

test('confirming or rejecting needs a pending proposal', function () {
    ['match' => $match, 'b1' => $b1] = scheduledMatch();
    Sanctum::actingAs($b1);

    postJson("/api/matches/{$match->id}/result-proposal/confirm")->assertUnprocessable();
    postJson("/api/matches/{$match->id}/result-proposal/reject")->assertUnprocessable();
});

test('a proposed result does not count for stats until it is confirmed', function () {
    ['match' => $match, 'a1' => $a1, 'b1' => $b1] = scheduledMatch();
    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();

    expect($a1->playerSummary()['stats']['matches_played'])->toBe(0);

    Sanctum::actingAs($b1);
    postJson("/api/matches/{$match->id}/result-proposal/confirm")->assertOk();

    expect($a1->fresh()->playerSummary()['stats']['wins'])->toBe(1);
});

test('a proposed result is confirmed automatically after 48 hours without a rival answering', function () {
    ['match' => $match, 'a1' => $a1] = scheduledMatch();
    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();
    $proposedAt = $match->fresh()->result_proposed_at;

    $this->travelTo($proposedAt->copy()->addHours(47));
    $this->artisan('matches:auto-confirm-results')->assertSuccessful();
    expect($match->fresh()->status)->toBe('pending_validation');

    $this->travelTo($proposedAt->copy()->addHours(48));
    $this->artisan('matches:auto-confirm-results')->assertSuccessful();
    expect($match->fresh()->status)->toBe('completed')->and($match->fresh()->winner_side)->toBe(1);
    expect($a1->fresh()->playerSummary()['stats']['wins'])->toBe(1);
});

test('a rejected proposal starts a fresh 48 hour clock when proposed again', function () {
    ['match' => $match, 'a1' => $a1, 'b1' => $b1] = scheduledMatch();
    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();
    $first = $match->fresh()->result_proposed_at;

    Sanctum::actingAs($b1);
    postJson("/api/matches/{$match->id}/result-proposal/reject")->assertOk();
    expect($match->fresh()->result_proposed_at)->toBeNull();

    $this->travelTo($first->copy()->addHours(30));
    Sanctum::actingAs($a1);
    postJson("/api/matches/{$match->id}/result-proposal", wonInTwoSets())->assertCreated();

    $this->travelTo($first->copy()->addHours(60));
    $this->artisan('matches:auto-confirm-results')->assertSuccessful();
    expect($match->fresh()->status)->toBe('pending_validation');
});

test('the auto-confirm command is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'matches:auto-confirm-results'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');
});

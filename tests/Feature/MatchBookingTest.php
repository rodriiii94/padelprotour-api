<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Phase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

/**
 * @return array{match: PadelMatch, organizer: User, players: list<User>}
 */
function bookingMatch(): array
{
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $phase = Phase::factory()->create(['category_id' => Category::factory()->create(['competition_id' => $competition->id])->id]);
    $players = User::factory()->count(4)->create()->all();

    $match = PadelMatch::factory()->create([
        'phase_id' => $phase->id,
        'side1_player1_id' => $players[0]->id, 'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id, 'side2_player2_id' => $players[3]->id,
        'scheduled_at' => null, 'court' => null,
    ]);

    return compact('match', 'organizer', 'players');
}

test('a player links the playtomic booking and it shows up on the match', function () {
    ['match' => $match, 'players' => $players] = bookingMatch();
    Sanctum::actingAs($players[2]);

    putJson("/api/matches/{$match->id}/booking", [
        'playtomic_url' => 'https://app.playtomic.com/t/7xUxNkNN',
        'scheduled_at' => '2026-10-04T19:30:00+02:00',
        'club' => 'Club Pádel Norte',
        'court' => 'Pista 3',
    ])->assertOk()->assertJson([
        'club' => 'Club Pádel Norte',
        'court' => 'Pista 3',
        'playtomic_url' => 'https://app.playtomic.com/t/7xUxNkNN',
    ]);

    getJson("/api/matches/{$match->id}")->assertOk()->assertJson([
        'club' => 'Club Pádel Norte',
        'playtomic_url' => 'https://app.playtomic.com/t/7xUxNkNN',
    ]);
    expect($match->fresh()->scheduled_at->utc()->toIso8601String())->toBe('2026-10-04T17:30:00+00:00');
});

test('the organizer can edit the booking but someone outside the match cannot', function () {
    ['match' => $match, 'organizer' => $organizer] = bookingMatch();

    Sanctum::actingAs($organizer);
    putJson("/api/matches/{$match->id}/booking", ['club' => 'Club Sur'])->assertOk();

    Sanctum::actingAs(User::factory()->create());
    putJson("/api/matches/{$match->id}/booking", ['club' => 'Intruso'])->assertForbidden();

    expect($match->fresh()->club)->toBe('Club Sur');
});

test('unlinking clears the booking fields', function () {
    ['match' => $match, 'players' => $players] = bookingMatch();
    $match->update(['club' => 'Club Sur', 'playtomic_url' => 'https://app.playtomic.com/t/abc']);
    Sanctum::actingAs($players[0]);

    putJson("/api/matches/{$match->id}/booking", ['club' => null, 'playtomic_url' => null, 'scheduled_at' => null, 'court' => null])
        ->assertOk();

    expect($match->fresh())->club->toBeNull()->playtomic_url->toBeNull();
});

test('only https playtomic links are accepted', function (string $url) {
    ['match' => $match, 'players' => $players] = bookingMatch();
    Sanctum::actingAs($players[0]);

    putJson("/api/matches/{$match->id}/booking", ['playtomic_url' => $url])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('playtomic_url');
})->with([
    'http' => 'http://app.playtomic.com/t/7xUxNkNN',
    'other domain' => 'https://evil.com/t/7xUxNkNN',
    'lookalike domain' => 'https://playtomic.com.evil.com/t/1',
    'javascript' => 'javascript:alert(1)',
]);

test('the booking cannot change on a cancelled competition', function () {
    ['match' => $match, 'players' => $players] = bookingMatch();
    $match->phase->category->competition->forceFill(['cancelled_at' => now()])->save();
    Sanctum::actingAs($players[0]);

    putJson("/api/matches/{$match->id}/booking", ['club' => 'Club Sur'])->assertUnprocessable();
});

test('my clubs lists the distinct clubs of my own matches, newest first', function () {
    ['match' => $match, 'players' => $players] = bookingMatch();
    $match->update(['club' => 'Club Viejo']);
    $this->travel(1)->minutes();
    PadelMatch::factory()->create(['side1_player1_id' => $players[0]->id, 'club' => 'Club Nuevo']);
    PadelMatch::factory()->create(['side2_player2_id' => $players[0]->id, 'club' => 'Club Nuevo']);
    PadelMatch::factory()->create(['club' => 'Club Ajeno']);

    Sanctum::actingAs($players[0]);

    getJson('/api/me/clubs')->assertOk()->assertExactJson(['Club Nuevo', 'Club Viejo']);
});

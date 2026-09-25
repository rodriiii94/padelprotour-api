<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\MatchMessage;
use App\Models\PadelMatch;
use App\Models\Phase;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * @return array{match: PadelMatch, organizer: User, players: list<User>}
 */
function chatMatch(): array
{
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $phase = Phase::factory()->create(['category_id' => Category::factory()->create(['competition_id' => $competition->id])->id]);
    $players = User::factory()->count(4)->create()->all();

    $match = PadelMatch::factory()->create([
        'phase_id' => $phase->id,
        'side1_player1_id' => $players[0]->id, 'side1_player2_id' => $players[1]->id,
        'side2_player1_id' => $players[2]->id, 'side2_player2_id' => $players[3]->id,
    ]);

    return compact('match', 'organizer', 'players');
}

test('guests cannot read or write the match chat', function () {
    ['match' => $match] = chatMatch();

    getJson("/api/matches/{$match->id}/messages")->assertUnauthorized();
    postJson("/api/matches/{$match->id}/messages", ['body' => 'hola'])->assertUnauthorized();
});

test('the four players and the organizer can chat; anyone else cannot', function () {
    ['match' => $match, 'organizer' => $organizer, 'players' => $players] = chatMatch();

    foreach ([...$players, $organizer] as $member) {
        Sanctum::actingAs($member);
        postJson("/api/matches/{$match->id}/messages", ['body' => "Hola de {$member->id}"])->assertCreated();
        getJson("/api/matches/{$match->id}/messages")->assertOk();
    }

    Sanctum::actingAs(User::factory()->create());
    getJson("/api/matches/{$match->id}/messages")->assertForbidden();
    postJson("/api/matches/{$match->id}/messages", ['body' => 'intruso'])->assertForbidden();

    expect($match->messages()->count())->toBe(5);
});

test('messages are listed newest first with the author public summary and never an email', function () {
    ['match' => $match, 'players' => $players] = chatMatch();
    MatchMessage::factory()->create(['match_id' => $match->id, 'author_id' => $players[0]->id, 'body' => 'primero']);
    MatchMessage::factory()->create(['match_id' => $match->id, 'author_id' => $players[2]->id, 'body' => 'segundo']);
    Sanctum::actingAs($players[1]);

    $response = getJson("/api/matches/{$match->id}/messages")->assertOk();

    expect($response->json('data.0.body'))->toBe('segundo')
        ->and($response->json('data.1.body'))->toBe('primero')
        ->and($response->json('data.0.author.id'))->toBe($players[2]->id)
        ->and($response->json('data.0.author'))->not->toHaveKeys(['email', 'avatar_path', 'provider']);
});

test('the chat only shows the messages of its own match', function () {
    ['match' => $match, 'players' => $players] = chatMatch();
    MatchMessage::factory()->create(['body' => 'de otro partido']);
    Sanctum::actingAs($players[0]);

    getJson("/api/matches/{$match->id}/messages")->assertOk()->assertJsonCount(0, 'data');
});

test('a message needs a body of up to 1000 characters and is trimmed', function () {
    ['match' => $match, 'players' => $players] = chatMatch();
    Sanctum::actingAs($players[0]);

    postJson("/api/matches/{$match->id}/messages", [])->assertUnprocessable();
    postJson("/api/matches/{$match->id}/messages", ['body' => str_repeat('a', 1001)])->assertUnprocessable();
    postJson("/api/matches/{$match->id}/messages", ['body' => '   Sábado a las 10   '])
        ->assertCreated()->assertJsonPath('body', 'Sábado a las 10');
});

test('authors delete their own messages and the organizer can delete any, but nobody else', function () {
    ['match' => $match, 'organizer' => $organizer, 'players' => $players] = chatMatch();
    $mine = MatchMessage::factory()->create(['match_id' => $match->id, 'author_id' => $players[0]->id]);
    $theirs = MatchMessage::factory()->create(['match_id' => $match->id, 'author_id' => $players[1]->id]);

    Sanctum::actingAs($players[0]);
    deleteJson("/api/match-messages/{$theirs->id}")->assertForbidden();
    deleteJson("/api/match-messages/{$mine->id}")->assertNoContent();

    Sanctum::actingAs($organizer);
    deleteJson("/api/match-messages/{$theirs->id}")->assertNoContent();

    expect($match->messages()->count())->toBe(0);
});

test('deleting a match deletes its chat', function () {
    ['match' => $match] = chatMatch();
    MatchMessage::factory()->count(2)->create(['match_id' => $match->id]);

    $match->delete();

    expect(MatchMessage::count())->toBe(0);
});

test('deleting an account deletes the messages the user wrote', function () {
    ['match' => $match, 'players' => $players] = chatMatch();
    MatchMessage::factory()->create(['match_id' => $match->id, 'author_id' => $players[0]->id]);
    MatchMessage::factory()->create(['match_id' => $match->id, 'author_id' => $players[1]->id]);

    $players[0]->anonymize();

    expect($match->messages()->pluck('author_id')->all())->toBe([$players[1]->id]);
});

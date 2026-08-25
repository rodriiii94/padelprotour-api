<?php

use App\Events\ChatMessageSent;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\Competition;
use App\Models\Pair;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('guests cannot access the competition chat', function () {
    $competition = Competition::factory()->create();

    getJson("/api/competitions/{$competition->id}/chat-messages")->assertUnauthorized();
});

test('a user unrelated to the competition cannot read or post in the chat', function () {
    $competition = Competition::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/competitions/{$competition->id}/chat-messages")->assertForbidden();
    postJson("/api/competitions/{$competition->id}/chat-messages", ['body' => 'Hola'])->assertForbidden();
});

test('the organizer can post and list chat messages', function () {
    Event::fake();
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    Sanctum::actingAs($organizer);

    postJson("/api/competitions/{$competition->id}/chat-messages", ['body' => 'Bienvenidos al torneo'])
        ->assertCreated()
        ->assertJsonFragment(['body' => 'Bienvenidos al torneo']);

    getJson("/api/competitions/{$competition->id}/chat-messages")->assertOk();

    Event::assertDispatched(ChatMessageSent::class);
});

test('a pair registrant can post in the chat', function () {
    Event::fake();
    $competition = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $pair->id, 'player_id' => null, 'status' => 'confirmed']);
    Sanctum::actingAs($user);

    postJson("/api/competitions/{$competition->id}/chat-messages", ['body' => 'Hola equipo'])->assertCreated();
});

test('an individual registrant can post in the chat', function () {
    Event::fake();
    $competition = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    $user = User::factory()->create();
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => null, 'player_id' => $user->id, 'status' => 'confirmed']);
    Sanctum::actingAs($user);

    postJson("/api/competitions/{$competition->id}/chat-messages", ['body' => 'Hola liga'])->assertCreated();
});

test('a rejected registration does not grant chat access', function () {
    $competition = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    $user = User::factory()->create();
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => null, 'player_id' => $user->id, 'status' => 'rejected']);
    Sanctum::actingAs($user);

    postJson("/api/competitions/{$competition->id}/chat-messages", ['body' => 'Hola'])->assertForbidden();
});

test('the author can delete their own message', function () {
    Event::fake();
    $competition = Competition::factory()->create();
    $author = User::factory()->create();
    $message = ChatMessage::factory()->create(['competition_id' => $competition->id, 'author_id' => $author->id]);
    Sanctum::actingAs($author);

    deleteJson("/api/chat-messages/{$message->id}")->assertNoContent();
});

test('the organizer can delete any message in their competition', function () {
    Event::fake();
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $message = ChatMessage::factory()->create(['competition_id' => $competition->id]);
    Sanctum::actingAs($organizer);

    deleteJson("/api/chat-messages/{$message->id}")->assertNoContent();
});

test('a participant cannot delete someone else\'s message', function () {
    $competition = Competition::factory()->create();
    $message = ChatMessage::factory()->create(['competition_id' => $competition->id]);
    Sanctum::actingAs(User::factory()->create());

    deleteJson("/api/chat-messages/{$message->id}")->assertForbidden();
});

<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\Pair;
use App\Models\Registration;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('guests cannot access registrations', function () {
    $category = Category::factory()->create();

    getJson("/api/categories/{$category->id}/registrations")->assertUnauthorized();
});

test('a user can register their own pair in a fixed pair category', function () {
    $competition = Competition::factory()->create(['type' => 'tournament']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['pair_id' => $pair->id])
        ->assertCreated()
        ->assertJsonFragment(['pair_id' => $pair->id, 'status' => 'pending']);
});

test('a user cannot register a pair they do not belong to', function () {
    $category = Category::factory()->create(['registration_mode' => null]);
    $pair = Pair::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/categories/{$category->id}/registrations", ['pair_id' => $pair->id])
        ->assertForbidden();
});

test('a user can register themselves individually in an individual rotating category', function () {
    $category = Category::factory()->create(['registration_mode' => 'individual_rotating']);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['player_id' => $user->id])
        ->assertCreated()
        ->assertJsonFragment(['player_id' => $user->id, 'status' => 'pending']);
});

test('a user cannot register another player individually', function () {
    $category = Category::factory()->create(['registration_mode' => 'individual_rotating']);
    $otherPlayer = User::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/categories/{$category->id}/registrations", ['player_id' => $otherPlayer->id])
        ->assertForbidden();
});

test('pair_id is rejected in an individual rotating category', function () {
    $category = Category::factory()->create(['registration_mode' => 'individual_rotating']);
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['pair_id' => $pair->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pair_id', 'player_id']);
});

test('player_id is rejected in a fixed pair category', function () {
    $category = Category::factory()->create(['registration_mode' => 'fixed_pair']);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['player_id' => $user->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['pair_id', 'player_id']);
});

test('registering twice for the same category is rejected', function () {
    $category = Category::factory()->create(['registration_mode' => 'individual_rotating']);
    $user = User::factory()->create();
    Registration::factory()->create([
        'category_id' => $category->id,
        'pair_id' => null,
        'player_id' => $user->id,
        'status' => 'confirmed',
    ]);
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['player_id' => $user->id])
        ->assertUnprocessable();
});

test('registering after the deadline is rejected', function () {
    $competition = Competition::factory()->create(['registration_closes_at' => now()->subDay()]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['player_id' => $user->id])
        ->assertUnprocessable();
});

test('registering to a cancelled competition is rejected', function () {
    $competition = Competition::factory()->create(['cancelled_at' => now()]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['player_id' => $user->id])
        ->assertUnprocessable();
});

test('the organizer can update a registration status', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id]);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $registration = Registration::factory()->create(['category_id' => $category->id]);
    Sanctum::actingAs($organizer);

    putJson("/api/registrations/{$registration->id}", ['status' => 'confirmed'])
        ->assertOk()
        ->assertJsonFragment(['status' => 'confirmed']);
});

test('a non organizer cannot update a registration status', function () {
    $registration = Registration::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    putJson("/api/registrations/{$registration->id}", ['status' => 'confirmed'])
        ->assertForbidden();
});

test('the registrant can withdraw their own pending registration', function () {
    $user = User::factory()->create();
    $registration = Registration::factory()->create([
        'pair_id' => null,
        'player_id' => $user->id,
        'status' => 'pending',
    ]);
    Sanctum::actingAs($user);

    deleteJson("/api/registrations/{$registration->id}")->assertNoContent();
});

test('the registrant cannot withdraw once the registration is confirmed', function () {
    $user = User::factory()->create();
    $registration = Registration::factory()->create([
        'pair_id' => null,
        'player_id' => $user->id,
        'status' => 'confirmed',
    ]);
    Sanctum::actingAs($user);

    deleteJson("/api/registrations/{$registration->id}")->assertForbidden();
});

test('an unrelated user cannot withdraw someone else\'s registration', function () {
    $registration = Registration::factory()->create(['status' => 'pending']);
    Sanctum::actingAs(User::factory()->create());

    deleteJson("/api/registrations/{$registration->id}")->assertForbidden();
});

test('a pair registration embeds both players', function () {
    $category = Category::factory()->create(['registration_mode' => null]);
    $user = User::factory()->create(['name' => 'Ana']);
    $partner = User::factory()->create(['name' => 'Luis']);
    $pair = Pair::factory()->create(['player1_id' => $user->id, 'player2_id' => $partner->id]);
    $registration = Registration::factory()->create(['category_id' => $category->id, 'pair_id' => $pair->id, 'player_id' => null]);
    Sanctum::actingAs($user);

    $json = getJson("/api/registrations/{$registration->id}")->assertOk()->json();

    expect($json['pair']['player1']['name'])->toBe('Ana')
        ->and($json['pair']['player2']['name'])->toBe('Luis');
});

test('an individual registration embeds the player', function () {
    $category = Category::factory()->create(['registration_mode' => 'individual_rotating']);
    $user = User::factory()->create(['name' => 'Marta']);
    $registration = Registration::factory()->create(['category_id' => $category->id, 'pair_id' => null, 'player_id' => $user->id]);
    Sanctum::actingAs($user);

    $json = getJson("/api/registrations/{$registration->id}")->assertOk()->json();

    expect($json['player']['name'])->toBe('Marta');
});

<?php

use App\Models\Pair;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('guests cannot create a pair', function () {
    postJson('/api/pairs', ['partner_id' => User::factory()->create()->id])->assertUnauthorized();
});

test('a user can form a pair with another player', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/pairs', ['partner_id' => $partner->id])
        ->assertCreated()
        ->assertJsonFragment(['player1_id' => $user->id, 'player2_id' => $partner->id]);

    $this->assertDatabaseHas('pairs', ['player1_id' => $user->id, 'player2_id' => $partner->id]);
});

test('a user cannot form a pair with themselves', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/pairs', ['partner_id' => $user->id])->assertUnprocessable();
});

test('forming the same pair twice is rejected regardless of order', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    Pair::factory()->create(['player1_id' => $partner->id, 'player2_id' => $user->id]);
    Sanctum::actingAs($user);

    postJson('/api/pairs', ['partner_id' => $partner->id])->assertUnprocessable();
});

test('a user can list the pairs they belong to', function () {
    $user = User::factory()->create();
    $ownPair = Pair::factory()->create(['player1_id' => $user->id]);
    Pair::factory()->create();

    Sanctum::actingAs($user);

    $response = getJson('/api/pairs')->assertOk();

    expect(collect($response->json())->pluck('id')->all())->toBe([$ownPair->id]);
});

test('a player of the pair can view it', function () {
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    getJson("/api/pairs/{$pair->id}")->assertOk()->assertJsonFragment(['id' => $pair->id]);
});

test('someone outside the pair cannot view it', function () {
    $pair = Pair::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/pairs/{$pair->id}")->assertForbidden();
});

test('a user can name their pair when forming it', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/pairs', ['partner_id' => $partner->id, 'name' => 'Los Invencibles'])
        ->assertCreated()
        ->assertJsonFragment(['name' => 'Los Invencibles']);

    $this->assertDatabaseHas('pairs', ['player1_id' => $user->id, 'player2_id' => $partner->id, 'name' => 'Los Invencibles']);
});

test('a pair name is optional', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/pairs', ['partner_id' => $partner->id])
        ->assertCreated()
        ->assertJsonFragment(['name' => null]);
});

test('pair responses embed player1 and player2 names', function () {
    $user = User::factory()->create(['name' => 'Ana']);
    $partner = User::factory()->create(['name' => 'Luis']);
    Sanctum::actingAs($user);

    $created = postJson('/api/pairs', ['partner_id' => $partner->id])->assertCreated()->json();
    expect($created['player1'])->toBe(['id' => $user->id, 'name' => 'Ana'])
        ->and($created['player2'])->toBe(['id' => $partner->id, 'name' => 'Luis']);

    $shown = getJson("/api/pairs/{$created['id']}")->assertOk()->json();
    expect($shown['player1']['name'])->toBe('Ana');

    $indexed = getJson('/api/pairs')->assertOk()->json();
    expect($indexed[0]['player2']['name'])->toBe('Luis');
});

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

test('any authenticated user can view a specific pair', function () {
    $pair = Pair::factory()->create();
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/pairs/{$pair->id}")->assertOk()->assertJsonFragment(['id' => $pair->id]);
});

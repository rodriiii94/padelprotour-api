<?php

use App\Models\Competition;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('guests cannot follow or list followers', function () {
    $user = User::factory()->create();

    postJson("/api/users/{$user->id}/follow")->assertUnauthorized();
    getJson("/api/users/{$user->id}/followers")->assertUnauthorized();
});

test('a user follows and unfollows another, and following twice is harmless', function () {
    [$me, $other] = User::factory()->count(2)->create();
    Sanctum::actingAs($me);

    postJson("/api/users/{$other->id}/follow")->assertNoContent();
    postJson("/api/users/{$other->id}/follow")->assertNoContent();
    expect($me->following()->count())->toBe(1);

    deleteJson("/api/users/{$other->id}/follow")->assertNoContent();
    deleteJson("/api/users/{$other->id}/follow")->assertNoContent();
    expect($me->following()->count())->toBe(0);
});

test('nobody can follow themselves', function () {
    $me = User::factory()->create();
    Sanctum::actingAs($me);

    postJson("/api/users/{$me->id}/follow")->assertUnprocessable();
});

test('a profile shows follower counts and whether the viewer follows it', function () {
    [$me, $other, $fan] = User::factory()->count(3)->create();
    $fan->following()->attach($other->id);
    $other->following()->attach($fan->id);
    Sanctum::actingAs($me);

    getJson("/api/users/{$other->id}")
        ->assertOk()
        ->assertJsonPath('followers_count', 1)
        ->assertJsonPath('following_count', 1)
        ->assertJsonPath('is_following', false);

    postJson("/api/users/{$other->id}/follow");

    getJson("/api/users/{$other->id}")->assertJsonPath('followers_count', 2)->assertJsonPath('is_following', true);
});

test('followers and following lists expose only public fields, never the email', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $other->following()->attach($me->id);
    $me->following()->attach($other->id);
    Sanctum::actingAs($me);

    $followers = getJson("/api/users/{$me->id}/followers")->assertOk();
    $following = getJson("/api/users/{$me->id}/following")->assertOk();

    expect($followers->json('data.0.id'))->toBe($other->id)
        ->and($following->json('data.0.id'))->toBe($other->id)
        ->and($followers->json('data.0'))->not->toHaveKeys(['email', 'pivot', 'provider']);
});

test('deleting an account removes its follows in both directions', function () {
    [$me, $other] = User::factory()->count(2)->create();
    $me->following()->attach($other->id);
    $other->following()->attach($me->id);

    $me->anonymize();

    expect($other->followers()->count())->toBe(0)->and($other->following()->count())->toBe(0);
});

test('competitions can be searched by name or venue and never return private or cancelled ones', function () {
    Sanctum::actingAs(User::factory()->create());
    Competition::factory()->create(['name' => 'Torneo Primavera', 'venue' => 'Club Sur', 'is_private' => false, 'cancelled_at' => null]);
    Competition::factory()->create(['name' => 'Liga Otoño', 'venue' => 'Padel Primavera Center', 'is_private' => false, 'cancelled_at' => null]);
    Competition::factory()->create(['name' => 'Primavera Privada', 'is_private' => true, 'invite_token' => 'x']);
    Competition::factory()->create(['name' => 'Primavera Cancelada', 'is_private' => false, 'cancelled_at' => now()]);
    Competition::factory()->create(['name' => 'Otra Cosa', 'is_private' => false, 'cancelled_at' => null]);

    $names = collect(getJson('/api/competitions?search=primavera')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Torneo Primavera', 'Liga Otoño')
        ->not->toContain('Primavera Privada', 'Primavera Cancelada', 'Otra Cosa');
    getJson('/api/competitions?search=a')->assertUnprocessable();
});

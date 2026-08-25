<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\Pair;
use App\Models\Registration;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

test('guests cannot access the my-registrations or my-competitions endpoints', function () {
    getJson('/api/me/registrations')->assertUnauthorized();
    getJson('/api/me/competitions')->assertUnauthorized();
});

test('my registrations includes direct and pair based registrations, excluding others', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $ownIndividual = Registration::factory()->create([
        'category_id' => $category->id,
        'pair_id' => null,
        'player_id' => $user->id,
    ]);

    $pair = Pair::factory()->create(['player2_id' => $user->id]);
    $ownViaPair = Registration::factory()->create([
        'category_id' => $category->id,
        'pair_id' => $pair->id,
        'player_id' => null,
    ]);

    Registration::factory()->create(['category_id' => $category->id]);

    Sanctum::actingAs($user);

    $ids = collect(getJson('/api/me/registrations')->assertOk()->json())->pluck('id');

    expect($ids->sort()->values()->all())->toBe(collect([$ownIndividual->id, $ownViaPair->id])->sort()->values()->all());
});

test('my competitions includes organized and participated competitions, excluding unrelated ones', function () {
    $user = User::factory()->create();

    $organized = Competition::factory()->create(['organizer_id' => $user->id]);

    $participatedIn = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $participatedIn->id]);
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => null, 'player_id' => $user->id, 'status' => 'confirmed']);

    $unrelated = Competition::factory()->create();

    Sanctum::actingAs($user);

    $ids = collect(getJson('/api/me/competitions')->assertOk()->json())->pluck('id');

    expect($ids)->toContain($organized->id)
        ->toContain($participatedIn->id)
        ->not->toContain($unrelated->id);
});

test('a rejected registration does not count towards my competitions', function () {
    $user = User::factory()->create();
    $competition = Competition::factory()->create();
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    Registration::factory()->create(['category_id' => $category->id, 'pair_id' => null, 'player_id' => $user->id, 'status' => 'rejected']);

    Sanctum::actingAs($user);

    $ids = collect(getJson('/api/me/competitions')->assertOk()->json())->pluck('id');

    expect($ids)->not->toContain($competition->id);
});

<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('creating a competition generates an invite token visible to its organizer', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/competitions', [
        'type' => 'tournament',
        'name' => 'Torneo Privado',
        'start_date' => now()->addWeek()->toDateString(),
        'is_private' => true,
    ])->assertCreated();

    expect($response->json('invite_token'))->toBeString()->not->toBeEmpty();
    $this->assertDatabaseHas('competitions', ['name' => 'Torneo Privado', 'is_private' => true]);
});

test('the public competitions list excludes private competitions, even for their organizer', function () {
    $organizer = User::factory()->create();
    $public = Competition::factory()->create(['organizer_id' => $organizer->id, 'is_private' => false]);
    $private = Competition::factory()->create(['organizer_id' => $organizer->id, 'is_private' => true, 'invite_token' => Str::random(32)]);
    Sanctum::actingAs($organizer);

    $ids = collect(getJson('/api/competitions')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($public->id)->not->toContain($private->id);
});

test('a private competition appears in me/competitions for its organizer and its participants', function () {
    $organizer = User::factory()->create();
    $participant = User::factory()->create();
    $private = Competition::factory()->create(['organizer_id' => $organizer->id, 'is_private' => true, 'invite_token' => Str::random(32)]);
    $category = Category::factory()->create(['competition_id' => $private->id]);
    Registration::factory()->create([
        'category_id' => $category->id, 'pair_id' => null, 'player_id' => $participant->id, 'status' => 'confirmed',
    ]);

    Sanctum::actingAs($organizer);
    expect(collect(getJson('/api/me/competitions')->assertOk()->json())->pluck('id'))->toContain($private->id);

    Sanctum::actingAs($participant);
    expect(collect(getJson('/api/me/competitions')->assertOk()->json())->pluck('id'))->toContain($private->id);
});

test('a non participant cannot view a private competition directly', function () {
    $private = Competition::factory()->create(['is_private' => true, 'invite_token' => Str::random(32)]);
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/competitions/{$private->id}")->assertForbidden();
});

test('a participant can view a private competition, but does not see the invite token', function () {
    $organizer = User::factory()->create();
    $participant = User::factory()->create();
    $private = Competition::factory()->create(['organizer_id' => $organizer->id, 'is_private' => true, 'invite_token' => Str::random(32)]);
    $category = Category::factory()->create(['competition_id' => $private->id]);
    Registration::factory()->create([
        'category_id' => $category->id, 'pair_id' => null, 'player_id' => $participant->id, 'status' => 'confirmed',
    ]);
    Sanctum::actingAs($participant);

    $json = getJson("/api/competitions/{$private->id}")->assertOk()->json();

    expect($json)->not->toHaveKey('invite_token');
});

test('the organizer sees the invite token when viewing their own competition', function () {
    $organizer = User::factory()->create();
    $private = Competition::factory()->create(['organizer_id' => $organizer->id, 'is_private' => true, 'invite_token' => 'known-token-123']);
    Sanctum::actingAs($organizer);

    getJson("/api/competitions/{$private->id}")
        ->assertOk()
        ->assertJsonFragment(['invite_token' => 'known-token-123']);
});

test('guests cannot resolve an invite link', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'some-token']);

    getJson('/api/invites/some-token')->assertUnauthorized();
});

test('an unknown invite token returns not found', function () {
    Sanctum::actingAs(User::factory()->create());

    getJson('/api/invites/does-not-exist')->assertNotFound();
});

test('a valid invite token resolves the private competition regardless of prior participation', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'known-token-456']);
    Sanctum::actingAs(User::factory()->create());

    getJson('/api/invites/known-token-456')
        ->assertOk()
        ->assertJsonFragment(['id' => $competition->id]);
});

test('guests cannot resolve categories via an invite link', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'some-token']);
    Category::factory()->create(['competition_id' => $competition->id]);

    getJson('/api/invites/some-token/categories')->assertUnauthorized();
});

test('an unknown invite token returns not found for categories too', function () {
    Sanctum::actingAs(User::factory()->create());

    getJson('/api/invites/does-not-exist/categories')->assertNotFound();
});

test('a non participant can list categories of a private competition via its invite token', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'known-token-789']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'name' => '3ª Mixta']);
    Sanctum::actingAs(User::factory()->create());

    getJson('/api/invites/known-token-789/categories')
        ->assertOk()
        ->assertJsonFragment(['id' => $category->id, 'name' => '3ª Mixta']);
});

test('invite lookups are rate limited', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'rate-limit-token']);
    Sanctum::actingAs(User::factory()->create());

    for ($i = 0; $i < 10; $i++) {
        getJson('/api/invites/rate-limit-token')->assertOk();
    }

    getJson('/api/invites/rate-limit-token')->assertStatus(429);
});

test('regenerating the invite link replaces the token and invalidates the old one', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'is_private' => true, 'invite_token' => 'old-token']);
    Sanctum::actingAs($organizer);

    $newToken = postJson("/api/competitions/{$competition->id}/regenerate-invite")
        ->assertOk()
        ->json('invite_token');

    expect($newToken)->toBeString()->not->toBe('old-token')->toHaveLength(32);
    getJson('/api/invites/old-token')->assertNotFound();
    getJson("/api/invites/{$newToken}")->assertOk();
});

test('only the organizer can regenerate the invite link', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'old-token']);
    Sanctum::actingAs(User::factory()->create());

    postJson("/api/competitions/{$competition->id}/regenerate-invite")->assertForbidden();

    expect($competition->fresh()->invite_token)->toBe('old-token');
});

test('an invited user can read a private competition and its category with the invite token, and joins from there', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'invite-token']);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/categories/{$category->id}")->assertForbidden();
    getJson("/api/categories/{$category->id}?invite=wrong-token")->assertForbidden();
    getJson("/api/competitions/{$competition->id}?invite=wrong-token")->assertForbidden();

    getJson("/api/categories/{$category->id}?invite=invite-token")->assertOk();
    getJson("/api/competitions/{$competition->id}?invite=invite-token")->assertOk();
    getJson("/api/categories/{$category->id}/phases?invite=invite-token")->assertOk();
    getJson("/api/categories/{$category->id}/rankings?invite=invite-token")->assertOk();
});

test('the invite token does not open a different private competition', function () {
    $other = Competition::factory()->create(['is_private' => true, 'invite_token' => 'other-token']);
    Competition::factory()->create(['is_private' => true, 'invite_token' => 'invite-token']);
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/competitions/{$other->id}?invite=invite-token")->assertForbidden();
});

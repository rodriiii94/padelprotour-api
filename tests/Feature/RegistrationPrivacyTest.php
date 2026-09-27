<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\Pair;
use App\Models\Registration;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Un lector cualquiera (no organizador, no participante, sin invitación) no debe poder leer
 * ni escribir las inscripciones de una competición privada solo por conocer o adivinar el id
 * de su categoría — el mismo control de acceso que ya protege /categories, /phases, /matches
 * y /rankings de esa competición.
 */
test('an outsider cannot list registrations of a private competition', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'secret-token']);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    Registration::factory()->create(['category_id' => $category->id]);
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/categories/{$category->id}/registrations")->assertForbidden();
});

test('an outsider cannot view a single registration of a private competition', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'secret-token']);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    $registration = Registration::factory()->create(['category_id' => $category->id]);
    Sanctum::actingAs(User::factory()->create());

    getJson("/api/registrations/{$registration->id}")->assertForbidden();
});

test('an outsider cannot self-register into a private competition without the invite token', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'secret-token']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    postJson("/api/categories/{$category->id}/registrations", ['pair_id' => $pair->id])->assertForbidden();

    expect(Registration::where('category_id', $category->id)->count())->toBe(0);
});

test('a wrong invite token does not grant access to a private competition either', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'secret-token']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    getJson("/api/categories/{$category->id}/registrations?invite=wrong-token")->assertForbidden();
    postJson("/api/categories/{$category->id}/registrations?invite=wrong-token", ['pair_id' => $pair->id])
        ->assertForbidden();
});

test('presenting the correct invite token lets an outsider read and self-register', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'secret-token']);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    getJson("/api/categories/{$category->id}/registrations?invite=secret-token")->assertOk();

    postJson("/api/categories/{$category->id}/registrations?invite=secret-token", ['pair_id' => $pair->id])
        ->assertCreated();
});

test('once already a participant, no token is needed for another category of the same private competition', function () {
    $competition = Competition::factory()->create(['is_private' => true, 'invite_token' => 'secret-token']);
    $joinedCategory = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => 'individual_rotating']);
    $otherCategory = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $user = User::factory()->create();
    Registration::factory()->create([
        'category_id' => $joinedCategory->id, 'pair_id' => null, 'player_id' => $user->id, 'status' => 'confirmed',
    ]);
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    getJson("/api/categories/{$otherCategory->id}/registrations")->assertOk();
    postJson("/api/categories/{$otherCategory->id}/registrations", ['pair_id' => $pair->id])->assertCreated();
});

test('the organizer never needs a token for their own private competition', function () {
    $organizer = User::factory()->create();
    $competition = Competition::factory()->create(['organizer_id' => $organizer->id, 'is_private' => true, 'invite_token' => 'secret-token']);
    $category = Category::factory()->create(['competition_id' => $competition->id]);
    Registration::factory()->create(['category_id' => $category->id]);
    Sanctum::actingAs($organizer);

    getJson("/api/categories/{$category->id}/registrations")->assertOk();
});

test('a public competition still needs no invite token at all to list or join', function () {
    $competition = Competition::factory()->create(['is_private' => false]);
    $category = Category::factory()->create(['competition_id' => $competition->id, 'registration_mode' => null]);
    $user = User::factory()->create();
    $pair = Pair::factory()->create(['player1_id' => $user->id]);
    Sanctum::actingAs($user);

    getJson("/api/categories/{$category->id}/registrations")->assertOk();
    postJson("/api/categories/{$category->id}/registrations", ['pair_id' => $pair->id])->assertCreated();
});

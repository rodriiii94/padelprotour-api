<?php

use App\Models\Category;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

test('guests cannot delete an account', function () {
    deleteJson('/api/me', ['password' => 'secret-pass'])->assertUnauthorized();
});

test('the current password is required to delete an account', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);
    Sanctum::actingAs($user);

    deleteJson('/api/me')->assertUnprocessable()->assertJsonValidationErrors('password');
    deleteJson('/api/me', ['password' => 'wrong-pass'])->assertUnprocessable()->assertJsonValidationErrors('password');

    expect($user->fresh()->email)->not->toStartWith('eliminado-');
});

test('deleting an account wipes personal data and access but keeps other players history', function () {
    $user = User::factory()->create([
        'password' => Hash::make('secret-pass'),
        'bio' => 'Hola', 'city' => 'Madrid', 'social_links' => ['instagram' => 'yo'],
    ]);
    $user->createToken('web');
    $match = PadelMatch::factory()->create(['side1_player1_id' => $user->id, 'status' => 'completed', 'winner_side' => 1]);
    $pending = Registration::factory()->create([
        'category_id' => Category::factory()->create()->id, 'pair_id' => null, 'player_id' => $user->id, 'status' => 'pending',
    ]);
    Sanctum::actingAs($user);

    deleteJson('/api/me', ['password' => 'secret-pass'])->assertNoContent();

    $user->refresh();
    expect($user->name)->toBe('Jugador eliminado')
        ->and($user->email)->toBe("eliminado-{$user->id}@deleted.invalid")
        ->and($user->password)->toBeNull()
        ->and($user->bio)->toBeNull()
        ->and($user->social_links)->toBeNull()
        ->and($user->tokens()->count())->toBe(0);
    expect(PadelMatch::find($match->id))->not->toBeNull();
    $this->assertModelMissing($pending);
});

test('a deleted account can no longer log in, and its email can register again', function () {
    $user = User::factory()->create(['email' => 'yo@example.test', 'password' => Hash::make('secret-pass')]);
    Sanctum::actingAs($user);
    deleteJson('/api/me', ['password' => 'secret-pass'])->assertNoContent();

    postJson('/api/login', ['email' => 'yo@example.test', 'password' => 'secret-pass', 'device_name' => 'x'])
        ->assertUnprocessable();
    postJson('/api/register', ['name' => 'Yo Otra Vez', 'email' => 'yo@example.test', 'password' => 'secret-pass'])
        ->assertCreated();
});

test('social-login accounts confirm with their email instead of a password', function () {
    $user = User::factory()->create(['email' => 'social@example.test', 'password' => null, 'provider' => 'google', 'provider_id' => 'abc']);
    Sanctum::actingAs($user);

    deleteJson('/api/me', ['email' => 'otro@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
    deleteJson('/api/me', ['email' => 'Social@Example.test'])->assertNoContent();

    expect($user->fresh()->provider)->toBeNull();
});

test('an organizer with a running competition cannot delete the account until it is cancelled', function () {
    $user = User::factory()->create(['password' => Hash::make('secret-pass')]);
    $competition = Competition::factory()->create(['organizer_id' => $user->id, 'end_date' => null, 'cancelled_at' => null]);
    Sanctum::actingAs($user);

    deleteJson('/api/me', ['password' => 'secret-pass'])->assertUnprocessable()->assertJsonValidationErrors('account');
    expect($user->fresh()->name)->not->toBe('Jugador eliminado');

    $competition->forceFill(['cancelled_at' => now()])->save();

    deleteJson('/api/me', ['password' => 'secret-pass'])->assertNoContent();
});

test('me tells the app whether the account has a password', function () {
    Sanctum::actingAs(User::factory()->create(['password' => Hash::make('secret-pass')]));
    getJson('/api/me')->assertOk()->assertJsonPath('has_password', true)->assertJsonMissingPath('password');

    Sanctum::actingAs(User::factory()->create(['password' => null, 'provider' => 'google', 'provider_id' => 'x']));
    getJson('/api/me')->assertOk()->assertJsonPath('has_password', false);
});

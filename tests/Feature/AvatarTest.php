<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

beforeEach(fn () => Storage::fake('public'));

test('guests cannot upload a photo', function () {
    postJson('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)])->assertUnauthorized();
});

test('a photo is cropped to a 512px square, re-encoded and exposed as avatar_url', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('foto.png', 900, 600)], ['Accept' => 'application/json'])
        ->assertOk();

    $path = $user->fresh()->avatar_path;
    expect($path)->toStartWith('avatars/')->toEndWith('.jpg');
    Storage::disk('public')->assertExists($path);

    [$width, $height, $type] = getimagesizefromstring(Storage::disk('public')->get($path));
    expect([$width, $height, $type])->toBe([512, 512, IMAGETYPE_JPEG]);
    expect($response->json('avatar_url'))->toContain($path)->and($response->json())->not->toHaveKey('avatar_path');
});

test('only images are accepted', function () {
    Sanctum::actingAs(User::factory()->create());

    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('avatar');
    post('/api/me/avatar', [], ['Accept' => 'application/json'])->assertUnprocessable();
    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('tiny.jpg', 20, 20)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('avatar');
});

test('uploading a new photo replaces and deletes the previous file', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    $first = $user->fresh()->avatar_path;

    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('b.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    $second = $user->fresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

test('a user can remove their photo', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    $path = $user->fresh()->avatar_path;

    deleteJson('/api/me/avatar')->assertOk()->assertJsonPath('avatar_url', null);

    expect($user->fresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('other players see the photo in profiles, search and follow lists', function () {
    $owner = User::factory()->create(['name' => 'Fotogenico Perez']);
    Sanctum::actingAs($owner);
    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    $url = $owner->fresh()->avatar_url;

    $viewer = User::factory()->create();
    $viewer->following()->attach($owner->id);
    Sanctum::actingAs($viewer);

    getJson("/api/users/{$owner->id}")->assertOk()->assertJsonPath('avatar_url', $url)->assertJsonMissingPath('avatar_path');
    getJson('/api/users?search=Fotogenico')->assertOk()->assertJsonPath('0.avatar_url', $url)->assertJsonMissingPath('0.avatar_path');
    getJson("/api/users/{$viewer->id}/following")->assertOk()->assertJsonPath('data.0.avatar_url', $url);
});

test('deleting an account also deletes the photo file', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    $path = $user->fresh()->avatar_path;

    $user->fresh()->anonymize();

    Storage::disk('public')->assertMissing($path);
    expect($user->fresh()->avatar_path)->toBeNull();
});

test('an image over the pixel cap is rejected without ever trying to decode it', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // Por encima del tope (6 megapíxeles): un PNG de un solo color a este tamaño pesa muy
    // poco en disco pero, si se llegara a decodificar, exigiría decenas de MB de memoria.
    // Antes el tope era de 25 megapíxeles y una imagen así (aun por debajo de ese límite
    // más alto) tumbaba la petición con un 500 por agotar la memoria del proceso PHP.
    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('bomba.png', 4000, 3000)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('avatar');

    expect($user->fresh()->avatar_path)->toBeNull();
});

test('an image just under the pixel cap is still accepted', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    post('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('grande.png', 2400, 2400)], ['Accept' => 'application/json'])
        ->assertOk();

    expect($user->fresh()->avatar_path)->not->toBeNull();
});

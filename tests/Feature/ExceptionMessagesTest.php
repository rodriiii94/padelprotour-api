<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

test('a missing model on a bound route returns a generic 404 without leaking the model class', function () {
    Sanctum::actingAs(User::factory()->create());

    $response = getJson('/api/categories/999999')->assertNotFound();

    expect($response->json('message'))->toBe('No encontrado.')
        ->and($response->getContent())->not->toContain('App\\Models');
});

test('a truly missing route still returns its normal not-found message', function () {
    getJson('/api/esta-ruta-no-existe')
        ->assertNotFound()
        ->assertJsonPath('message', 'The route api/esta-ruta-no-existe could not be found.');
});

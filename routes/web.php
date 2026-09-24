<?php

use Illuminate\Support\Facades\Route;

// Esta app es una API pura para la app Expo -- no hay nada que "ver" en la
// raíz, así que en vez de la bienvenida de Laravel devolvemos algo útil
// para quien llegue aquí a mano (ver /up para el health check).
Route::get('/', function () {
    return response()->json([
        'name' => 'PadelProTour API',
        'docs' => 'https://github.com/rodriiii94/padelprotour-api/blob/main/openapi.yaml',
    ]);
});

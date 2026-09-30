<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Esta API no usa sesión/cookie (los clientes son Expo/móvil con
    // token Bearer), así que el canal de broadcasting se autentica con
    // el mismo guard `sanctum` que el resto de la API, no con `web`.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp') || $request->expectsJson(),
        );

        // Laravel convierte ModelNotFoundException en NotFoundHttpException antes de
        // pasar por aquí (ver Handler::prepareException), así que se intercepta esta y
        // no aquella. Su mensaje por defecto ("No query results for model
        // [App\Models\Xxx] 123") filtra el namespace/clase interno del modelo: no es un
        // dato sensible, pero no aporta nada al cliente. Un 404 de ruta inexistente (sin
        // ModelNotFoundException de por medio) conserva su mensaje normal.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (($request->is('api/*') || $request->expectsJson()) && $e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'No encontrado.'], 404);
            }
        });
    })->create();

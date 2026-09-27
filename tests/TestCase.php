<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cuerpo de respuesta que simula la API de haveibeenpwned.com para
     * Password::uncompromised() (ver AuthController::register). Vacío = "no aparece en
     * ninguna filtración" (lo normal en un test), así ninguno depende de que ese servicio
     * esté arriba ni choca con contraseñas de prueba habituales que sí están filtradas de
     * verdad (p.ej. "password123"). Un test que quiera simular una contraseña filtrada
     * cambia este valor antes de llamar a /api/register -- no hace falta tocar Http::fake.
     */
    protected static string $pwnedPasswordsResponseBody = '';

    protected function setUp(): void
    {
        parent::setUp();

        static::$pwnedPasswordsResponseBody = '';

        Http::preventStrayRequests();
        Http::fake([
            'api.pwnedpasswords.com/*' => fn () => Http::response(static::$pwnedPasswordsResponseBody, 200),
        ]);
    }
}

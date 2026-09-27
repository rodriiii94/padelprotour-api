<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Dos límites a la vez: por email+IP (no bloquea a todo el mundo detrás de la
        // misma IP) y por email solo, más laxo pero sin importar la IP -- si no, un
        // ataque repartido entre muchas IPs (habitual con proxies baratos) se salta el
        // primero probando siempre desde una IP distinta contra la misma cuenta.
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinutes(15, 15)->by('login-email:'.$email),
            ];
        });

        // Cuenta actual, no `email` (esta ruta es DELETE /me, ya autenticada): así los
        // intentos fallidos de un usuario no consumen el límite de otro que comparta IP.
        RateLimiter::for('account-delete', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?? $request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // invite_token es un secreto de un solo valor -- limita intentos
        // de fuerza bruta por usuario autenticado (la ruta exige auth:sanctum).
        RateLimiter::for('invite', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?? $request->ip());
        });

        RateLimiter::for('social-login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // email_verification_token es otro secreto de un solo valor.
        RateLimiter::for('email-verify', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}

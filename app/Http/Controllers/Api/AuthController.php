<?php

namespace App\Http\Controllers\Api;

use App\Auth\SocialLogin\AppleTokenVerifier;
use App\Auth\SocialLogin\GoogleTokenVerifier;
use App\Auth\SocialLogin\InvalidSocialTokenException;
use App\Auth\SocialLogin\OidcTokenVerifier;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Autenticación por token para clientes "de terceros" (móvil + web vía
// Expo), no autenticación de SPA por cookie -- por eso no usamos el guard
// "web"/sesión de Sanctum, solo emisión y verificación de tokens.
class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verification_token' => Str::random(40),
        ]);

        $user->notify(new VerifyEmailNotification($user->email_verification_token));

        return response()->json([
            'message' => 'Cuenta creada. Revisa tu email para verificarla antes de iniciar sesión.',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string'], // p.ej. "iphone-de-marta", "web-chrome"
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales no son correctas.'],
            ]);
        }

        abort_if($user->email_verified_at === null, 403, 'Verifica tu email antes de iniciar sesión.');

        return $this->issueToken($user, $validated['device_name']);
    }

    public function loginWithGoogle(Request $request, GoogleTokenVerifier $verifier)
    {
        return $this->loginWithProvider($request, $verifier, 'google');
    }

    public function loginWithApple(Request $request, AppleTokenVerifier $verifier)
    {
        return $this->loginWithProvider($request, $verifier, 'apple');
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function update(Request $request)
    {
        // Email y password se quedan fuera a propósito: cambiarlos aquí sin
        // reautenticación (contraseña actual, verificación de email...)
        // abriría una vía fácil de secuestro de cuenta.
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'level' => ['sometimes', 'nullable', 'string', 'max:255'],
            'club' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $request->user()->update($validated);

        return $request->user();
    }

    public function verifyEmail(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'device_name' => ['required', 'string'],
        ]);

        $user = User::where('email_verification_token', $validated['token'])->first();

        abort_unless($user, 404, 'Token de verificación no válido.');

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_token' => null,
        ])->save();

        return $this->issueToken($user, $validated['device_name']);
    }

    public function resendVerificationEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Misma respuesta exista o no la cuenta, para no filtrar por
        // enumeración qué emails están registrados.
        $user = User::whereNull('provider')
            ->whereNull('email_verified_at')
            ->where('email', $validated['email'])
            ->first();

        if ($user) {
            $user->forceFill(['email_verification_token' => Str::random(40)])->save();
            $user->notify(new VerifyEmailNotification($user->email_verification_token));
        }

        return response()->json([
            'message' => 'Si la cuenta existe y no está verificada, te hemos enviado un nuevo email.',
        ]);
    }

    /**
     * Busca o crea el usuario asociado a un `id_token` ya verificado por
     * un proveedor externo, y emite un token Sanctum para él.
     */
    private function loginWithProvider(Request $request, OidcTokenVerifier $verifier, string $provider)
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
            'device_name' => ['required', 'string'],
            // Apple solo manda el nombre real la primera vez, fuera del
            // id_token (en la respuesta nativa del SDK), no en el JWT.
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $claims = $verifier->verify($validated['id_token']);
        } catch (InvalidSocialTokenException $e) {
            abort(422, $e->getMessage());
        }

        $user = User::where('provider', $provider)->where('provider_id', $claims['provider_id'])->first();

        if (! $user) {
            abort_if(
                User::where('email', $claims['email'])->exists(),
                409,
                'Ya existe una cuenta con este email. Inicia sesión con tu método original.'
            );

            $user = User::create([
                'name' => $validated['name'] ?? $claims['name'] ?? explode('@', $claims['email'])[0],
                'email' => $claims['email'],
                'provider' => $provider,
                'provider_id' => $claims['provider_id'],
                'email_verified_at' => now(),
            ]);
        }

        return $this->issueToken($user, $validated['device_name']);
    }

    private function issueToken(User $user, string $deviceName)
    {
        // Un token por dispositivo: revocamos cualquier token previo con el
        // mismo nombre de dispositivo para no acumular tokens huérfanos si
        // el usuario vuelve a iniciar sesión desde el mismo móvil/navegador.
        $user->tokens()->where('name', $deviceName)->delete();

        return response()->json([
            'user' => $user,
            'token' => $user->createToken($deviceName)->plainTextToken,
        ]);
    }
}

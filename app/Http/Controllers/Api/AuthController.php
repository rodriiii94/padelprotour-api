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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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

        $this->sendVerificationEmail($user);

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

    /**
     * Elimina la cuenta (anonimiza, no borra el historial de los demás). Pide reautenticar:
     * la contraseña si la cuenta tiene, o el email si entra solo con Google/Apple.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();

        if ($user->password) {
            $request->validate(['password' => ['required', 'string']]);
            if (! Hash::check($request->input('password'), $user->password)) {
                throw ValidationException::withMessages(['password' => ['La contraseña no es correcta.']]);
            }
        } else {
            $request->validate(['email' => ['required', 'string']]);
            if (! hash_equals(Str::lower($user->email), Str::lower($request->input('email')))) {
                throw ValidationException::withMessages(['email' => ['El email no coincide con tu cuenta.']]);
            }
        }

        if ($user->hasActiveOrganizedCompetitions()) {
            throw ValidationException::withMessages([
                'account' => ['Organizas competiciones en curso. Cancélalas o elimínalas antes de borrar tu cuenta.'],
            ]);
        }

        $user->anonymize();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->append('name_change_available_at'));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // Email y password se quedan fuera a propósito: cambiarlos aquí sin
        // reautenticación (contraseña actual, verificación de email...)
        // abriría una vía fácil de secuestro de cuenta.
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:40'],
            'level' => ['sometimes', 'nullable', 'string', 'max:255'],
            'club' => ['sometimes', 'nullable', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'preferred_side' => ['sometimes', 'nullable', Rule::in(['right', 'left', 'both'])],
            'dominant_hand' => ['sometimes', 'nullable', Rule::in(['right', 'left'])],
            'avatar_color' => ['sometimes', 'nullable', Rule::in(User::AVATAR_COLORS)],
            // Solo símbolos/emojis: sin letras, números ni espacios.
            'avatar_emoji' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[^\p{L}\p{N}\s]+$/u'],
            'racket' => ['sometimes', 'nullable', 'string', 'max:80'],
            'motto' => ['sometimes', 'nullable', 'string', 'max:80'],
            'availability' => ['sometimes', 'nullable', 'array', 'max:21'],
            'availability.*' => ['string', Rule::in($this->availabilitySlots())],
            'social_links' => ['sometimes', 'nullable', 'array:'.implode(',', User::SOCIAL_NETWORKS)],
            // Se guarda solo el usuario, nunca una URL: la app arma el enlace
            // con el dominio de cada red, así no hay enlaces arbitrarios.
            'social_links.*' => ['nullable', 'string', 'regex:/^@?[A-Za-z0-9._-]{1,50}$/'],
        ]);

        if (isset($validated['name']) && $validated['name'] !== $user->name) {
            if ($availableAt = $user->name_change_available_at) {
                throw ValidationException::withMessages([
                    'name' => ['Solo puedes cambiar tu nombre cada '.User::NAME_CHANGE_INTERVAL_DAYS.' días. Podrás volver a cambiarlo el '.$availableAt->format('d/m/Y').'.'],
                ]);
            }

            $user->forceFill(['name_changed_at' => now()]);
        }

        if (isset($validated['availability'])) {
            $validated['availability'] = array_values(array_unique($validated['availability']));
        }

        if (array_key_exists('social_links', $validated) && is_array($validated['social_links'])) {
            $links = array_filter(array_map(fn ($handle) => $handle === null ? null : ltrim($handle, '@'), $validated['social_links']));
            $validated['social_links'] = $links === [] ? null : $links;
        }

        $user->update($validated);

        return $user->append('name_change_available_at');
    }

    /**
     * @return list<string> franjas como "mon-evening"
     */
    private function availabilitySlots(): array
    {
        return collect(User::AVAILABILITY_DAYS)
            ->crossJoin(User::AVAILABILITY_PARTS)
            ->map(fn (array $slot) => implode('-', $slot))
            ->all();
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
            $this->sendVerificationEmail($user);
        }

        return response()->json([
            'message' => 'Si la cuenta existe y no está verificada, te hemos enviado un nuevo email.',
        ]);
    }

    /**
     * Un fallo del proveedor de email (caído, límite de la cuenta, dominio
     * sin verificar en modo sandbox de Resend...) no debe tumbar el
     * registro entero ni dejar la cuenta en un estado raro -- la cuenta ya
     * se ha creado correctamente, el usuario simplemente podrá pedir que
     * se le reenvíe el email de verificación más tarde.
     */
    private function sendVerificationEmail(User $user): void
    {
        try {
            $user->notify(new VerifyEmailNotification($user->email_verification_token));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el email de verificación.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }
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
            'user' => $user->append('name_change_available_at'),
            'token' => $user->createToken($deviceName)->plainTextToken,
        ]);
    }
}

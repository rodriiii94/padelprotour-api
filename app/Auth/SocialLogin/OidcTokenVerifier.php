<?php

namespace App\Auth\SocialLogin;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifica un id_token OIDC (JWT) emitido por un proveedor externo (Google,
 * Apple...) contra sus claves públicas (JWKS), sin flujo de redirect: el
 * cliente móvil ya ha hecho el login nativo y nos manda el token resultante.
 */
abstract class OidcTokenVerifier
{
    abstract protected function jwksUrl(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function validIssuers(): array;

    /**
     * @return array<int, string>
     */
    abstract protected function audiences(): array;

    abstract protected function cacheKey(): string;

    /**
     * @return array{provider_id: string, email: string, email_verified: bool, name: ?string}
     */
    public function verify(string $idToken): array
    {
        $audiences = $this->audiences();

        if ($audiences === []) {
            throw new InvalidSocialTokenException('No hay ningún client id configurado para este proveedor.');
        }

        try {
            $payload = JWT::decode($idToken, $this->keys());
        } catch (Throwable $e) {
            throw new InvalidSocialTokenException('Token inválido o caducado.', previous: $e);
        }

        if (! in_array($payload->iss ?? null, $this->validIssuers(), true)) {
            throw new InvalidSocialTokenException('Emisor del token no reconocido.');
        }

        if (! in_array($payload->aud ?? null, $audiences, true)) {
            throw new InvalidSocialTokenException('Este token no es para esta aplicación.');
        }

        if (empty($payload->sub) || empty($payload->email)) {
            throw new InvalidSocialTokenException('El token no incluye los datos mínimos necesarios.');
        }

        $emailVerified = filter_var($payload->email_verified ?? true, FILTER_VALIDATE_BOOL);

        if (! $emailVerified) {
            throw new InvalidSocialTokenException('El proveedor no ha verificado este email todavía.');
        }

        return [
            'provider_id' => (string) $payload->sub,
            'email' => $payload->email,
            'email_verified' => true,
            'name' => $payload->name ?? null,
        ];
    }

    /**
     * @return array<string, Key>
     */
    private function keys(): array
    {
        $jwks = Cache::remember($this->cacheKey(), now()->addHours(6), fn () => Http::get($this->jwksUrl())->throw()->json());

        return JWK::parseKeySet($jwks);
    }
}

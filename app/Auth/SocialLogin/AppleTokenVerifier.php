<?php

namespace App\Auth\SocialLogin;

class AppleTokenVerifier extends OidcTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://appleid.apple.com/auth/keys';
    }

    protected function validIssuers(): array
    {
        return ['https://appleid.apple.com'];
    }

    /**
     * El `aud` del id_token nativo de Apple es el Bundle ID de la app
     * (no un Service ID como en el flujo web).
     */
    protected function audiences(): array
    {
        return config('services.apple.client_ids', []);
    }

    protected function cacheKey(): string
    {
        return 'oidc-jwks:apple';
    }
}

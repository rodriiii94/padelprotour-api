<?php

namespace App\Auth\SocialLogin;

class GoogleTokenVerifier extends OidcTokenVerifier
{
    protected function jwksUrl(): string
    {
        return 'https://www.googleapis.com/oauth2/v3/certs';
    }

    protected function validIssuers(): array
    {
        return ['accounts.google.com', 'https://accounts.google.com'];
    }

    protected function audiences(): array
    {
        return config('services.google.client_ids', []);
    }

    protected function cacheKey(): string
    {
        return 'oidc-jwks:google';
    }
}

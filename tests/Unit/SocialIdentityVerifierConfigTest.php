<?php

use App\Services\SocialIdentityVerifier;
use App\SocialAuthProvider;

it('uses the configured google audiences for token validation', function () {
    config([
        'services.google.client_ids' => [
            '189125442359-4gmfb7cg5t4g68ju4flcv781d46qgvik.apps.googleusercontent.com',
            'mobile-client-id.apps.googleusercontent.com',
        ],
        'services.google.issuers' => [
            'accounts.google.com',
            'https://accounts.google.com',
        ],
        'services.google.jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
    ]);

    $verifier = new class extends SocialIdentityVerifier
    {
        public function googleConfiguration(): array
        {
            return $this->configurationFor(SocialAuthProvider::Google);
        }
    };

    $configuration = $verifier->googleConfiguration();

    expect($configuration['client_ids'])->toBe([
        '189125442359-4gmfb7cg5t4g68ju4flcv781d46qgvik.apps.googleusercontent.com',
        'mobile-client-id.apps.googleusercontent.com',
    ]);
    expect($configuration['issuers'])->toContain('accounts.google.com');
    expect($configuration['jwks_url'])->toBe('https://www.googleapis.com/oauth2/v3/certs');
});

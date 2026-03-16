<?php

namespace App\Services;

use App\SocialAuthProvider;

class VerifiedSocialIdentity
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly SocialAuthProvider $provider,
        public readonly string $providerUserId,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly array $claims = [],
    ) {}
}

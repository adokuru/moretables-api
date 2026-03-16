<?php

namespace App\Services;

use App\SocialAuthProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SocialIdentityVerifier
{
    public function verify(SocialAuthProvider $provider, string $idToken): VerifiedSocialIdentity
    {
        [$header, $payload, $signature, $signingInput] = $this->parseToken($idToken);
        $configuration = $this->configurationFor($provider);

        if (($header['alg'] ?? null) !== 'RS256') {
            $this->invalidToken('Unsupported social identity token algorithm.');
        }

        $keyId = $header['kid'] ?? null;

        if (! is_string($keyId) || $keyId === '') {
            $this->invalidToken('Unable to determine the social identity token signing key.');
        }

        $publicKey = $this->publicKeyFor($provider, $keyId, $configuration['jwks_url']);
        $verificationResult = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verificationResult !== 1) {
            $this->invalidToken('The social identity token signature is invalid.');
        }

        $this->validateClaims($provider, $payload, $configuration);

        return new VerifiedSocialIdentity(
            provider: $provider,
            providerUserId: (string) $payload['sub'],
            email: isset($payload['email']) ? (string) $payload['email'] : null,
            emailVerified: filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            firstName: isset($payload['given_name']) ? (string) $payload['given_name'] : null,
            lastName: isset($payload['family_name']) ? (string) $payload['family_name'] : null,
            claims: $payload,
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: string}
     */
    protected function parseToken(string $idToken): array
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            $this->invalidToken('Malformed social identity token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true);
        $signature = $this->base64UrlDecodeToBinary($encodedSignature);

        if (! is_array($header) || ! is_array($payload)) {
            $this->invalidToken('Malformed social identity token.');
        }

        return [$header, $payload, $signature, $encodedHeader.'.'.$encodedPayload];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $payload
     */
    protected function validateClaims(SocialAuthProvider $provider, array $payload, array $configuration): void
    {
        $subject = $payload['sub'] ?? null;
        $issuer = $payload['iss'] ?? null;
        $audience = $payload['aud'] ?? null;
        $expiresAt = $payload['exp'] ?? null;

        if (! is_string($subject) || $subject === '') {
            $this->invalidToken('The social identity token subject is missing.');
        }

        if (! is_string($issuer) || ! in_array($issuer, $configuration['issuers'], true)) {
            $this->invalidToken('The social identity token issuer is invalid.');
        }

        if (! $this->matchesAudience($audience, $configuration['client_ids'])) {
            $this->invalidToken('The social identity token audience is invalid.');
        }

        if (! is_numeric($expiresAt) || (int) $expiresAt <= now()->timestamp) {
            $this->invalidToken('The social identity token has expired.');
        }
    }

    /**
     * @param  array<int, string>  $allowedAudiences
     */
    protected function matchesAudience(string|array|null $audience, array $allowedAudiences): bool
    {
        if ($allowedAudiences === []) {
            $this->invalidToken('Social login is not configured for this provider.');
        }

        $audiences = is_array($audience) ? $audience : [$audience];

        foreach ($audiences as $currentAudience) {
            if (is_string($currentAudience) && in_array($currentAudience, $allowedAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{client_ids: array<int, string>, issuers: array<int, string>, jwks_url: string}
     */
    protected function configurationFor(SocialAuthProvider $provider): array
    {
        if ($provider === SocialAuthProvider::Google) {
            return [
                'client_ids' => array_values(config('services.google.client_ids', [])),
                'issuers' => array_values(config('services.google.issuers', [])),
                'jwks_url' => (string) config('services.google.jwks_url'),
            ];
        }

        return [
            'client_ids' => array_values(config('services.apple.client_ids', [])),
            'issuers' => [(string) config('services.apple.issuer')],
            'jwks_url' => (string) config('services.apple.jwks_url'),
        ];
    }

    protected function publicKeyFor(SocialAuthProvider $provider, string $keyId, string $jwksUrl): mixed
    {
        $cacheKey = 'social-auth:jwks:'.$provider->value;
        $keys = Cache::get($cacheKey);

        if (! is_array($keys)) {
            $response = Http::acceptJson()
                ->timeout(10)
                ->get($jwksUrl)
                ->throw();

            $keys = $response->json('keys');

            if (! is_array($keys)) {
                $this->invalidToken('Unable to load the provider signing keys.');
            }

            Cache::put($cacheKey, $keys, now()->addSeconds($this->jwksCacheTtl($response)));
        }

        $jwk = collect($keys)->first(fn (mixed $key): bool => is_array($key) && ($key['kid'] ?? null) === $keyId);

        if (! is_array($jwk)) {
            $this->invalidToken('Unable to match the provider signing key.');
        }

        $publicKey = openssl_pkey_get_public($this->jwkToPem($jwk));

        if ($publicKey === false) {
            $this->invalidToken('Unable to read the provider signing key.');
        }

        return $publicKey;
    }

    protected function jwksCacheTtl(Response $response): int
    {
        $cacheControl = $response->header('Cache-Control', '');

        if (preg_match('/max-age=(\d+)/', $cacheControl, $matches) === 1) {
            return max((int) $matches[1], 60);
        }

        return 3600;
    }

    /**
     * @param  array<string, mixed>  $jwk
     */
    protected function jwkToPem(array $jwk): string
    {
        $modulus = $jwk['n'] ?? null;
        $exponent = $jwk['e'] ?? null;

        if (! is_string($modulus) || ! is_string($exponent)) {
            $this->invalidToken('The provider signing key is incomplete.');
        }

        $modulusBytes = $this->base64UrlDecodeToBinary($modulus);
        $exponentBytes = $this->base64UrlDecodeToBinary($exponent);

        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($modulusBytes).$this->asn1Integer($exponentBytes)
        );

        $algorithmIdentifier = $this->asn1Sequence(
            $this->asn1ObjectIdentifier("\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01").$this->asn1Null()
        );

        $subjectPublicKeyInfo = $this->asn1Sequence(
            $algorithmIdentifier.$this->asn1BitString($rsaPublicKey)
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    protected function asn1Sequence(string $value): string
    {
        return "\x30".$this->asn1Length(strlen($value)).$value;
    }

    protected function asn1Integer(string $value): string
    {
        if (ord($value[0]) > 0x7F) {
            $value = "\x00".$value;
        }

        return "\x02".$this->asn1Length(strlen($value)).$value;
    }

    protected function asn1BitString(string $value): string
    {
        return "\x03".$this->asn1Length(strlen($value) + 1)."\x00".$value;
    }

    protected function asn1ObjectIdentifier(string $value): string
    {
        return "\x06".$this->asn1Length(strlen($value)).$value;
    }

    protected function asn1Null(): string
    {
        return "\x05\x00";
    }

    protected function asn1Length(int $length): string
    {
        if ($length <= 0x7F) {
            return chr($length);
        }

        $encoded = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($encoded)).$encoded;
    }

    protected function base64UrlDecode(string $value): string
    {
        return $this->base64UrlDecodeToBinary($value);
    }

    protected function base64UrlDecodeToBinary(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        if ($decoded === false) {
            $this->invalidToken('Malformed social identity token.');
        }

        return $decoded;
    }

    protected function invalidToken(string $message): never
    {
        throw ValidationException::withMessages([
            'id_token' => [$message],
        ]);
    }
}

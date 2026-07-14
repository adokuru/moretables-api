<?php

namespace App\Services;

use App\Exceptions\GoogleSheetsWaitlistException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

class GoogleSheetsWaitlistService
{
    private const GOOGLE_SHEETS_SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    public function append(string $email, CarbonInterface $joinedAt): void
    {
        $credentials = $this->credentials();
        $spreadsheetId = $this->requiredConfig('services.campaign_waitlist.spreadsheet_id');
        $sheetRange = $this->requiredConfig('services.campaign_waitlist.sheet_range');

        try {
            Http::withToken($this->accessToken($credentials))
                ->acceptJson()
                ->asJson()
                ->withQueryParameters([
                    'valueInputOption' => 'RAW',
                    'insertDataOption' => 'INSERT_ROWS',
                ])
                ->timeout(10)
                ->retry([100, 250])
                ->post(
                    'https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($spreadsheetId).'/values/'.rawurlencode($sheetRange).':append',
                    [
                        'majorDimension' => 'ROWS',
                        'values' => [
                            [$email, $joinedAt->toIso8601String()],
                        ],
                    ],
                )
                ->throw();
        } catch (GoogleSheetsWaitlistException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new GoogleSheetsWaitlistException(
                'The campaign waitlist could not be updated in Google Sheets.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri?: string}
     */
    private function credentials(): array
    {
        $encodedCredentials = $this->requiredConfig('services.campaign_waitlist.service_account_base64');
        $decodedCredentials = base64_decode($encodedCredentials, true);

        if ($decodedCredentials === false) {
            throw new GoogleSheetsWaitlistException('The Google service-account credential is not valid base64.');
        }

        try {
            $credentials = json_decode($decodedCredentials, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GoogleSheetsWaitlistException(
                'The decoded Google service-account credential is not valid JSON.',
                previous: $exception,
            );
        }

        if (! is_array($credentials)
            || ! is_string($credentials['client_email'] ?? null)
            || ! is_string($credentials['private_key'] ?? null)
        ) {
            throw new GoogleSheetsWaitlistException(
                'The Google service-account credential must contain client_email and private_key values.',
            );
        }

        /** @var array{client_email: string, private_key: string, token_uri?: string} $credentials */
        return $credentials;
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri?: string}  $credentials
     */
    private function accessToken(array $credentials): string
    {
        $cacheKey = 'campaign-waitlist:google-token:'.hash('sha256', $credentials['client_email']);

        return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($credentials): string {
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout(10)
                    ->retry([100, 250])
                    ->post($tokenUri, [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $this->signedAssertion($credentials, $tokenUri),
                    ])
                    ->throw();
            } catch (GoogleSheetsWaitlistException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new GoogleSheetsWaitlistException(
                    'Google rejected the service-account authentication request.',
                    previous: $exception,
                );
            }

            $accessToken = $response->json('access_token');

            if (! is_string($accessToken) || $accessToken === '') {
                throw new GoogleSheetsWaitlistException('Google did not return an access token.');
            }

            return $accessToken;
        });
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri?: string}  $credentials
     */
    private function signedAssertion(array $credentials, string $tokenUri): string
    {
        $issuedAt = now()->timestamp;
        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => self::GOOGLE_SHEETS_SCOPE,
            'aud' => $tokenUri,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsignedToken = $header.'.'.$claims;

        if (! openssl_sign($unsignedToken, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new GoogleSheetsWaitlistException('The Google service-account JWT could not be signed.');
        }

        return $unsignedToken.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function requiredConfig(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || trim($value) === '') {
            throw new GoogleSheetsWaitlistException("Missing required configuration: {$key}.");
        }

        return $value;
    }
}

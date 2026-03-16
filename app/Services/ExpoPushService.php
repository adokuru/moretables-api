<?php

namespace App\Services;

use App\Notifications\ExpoPushMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushService
{
    /**
     * @param  array<int, string>  $tokens
     */
    public function send(array $tokens, ExpoPushMessage $message): void
    {
        collect($tokens)
            ->chunk(100)
            ->each(function ($tokenChunk) use ($message): void {
                $request = Http::acceptJson()
                    ->asJson()
                    ->timeout(10);

                $accessToken = config('services.expo.access_token');

                if (is_string($accessToken) && $accessToken !== '') {
                    $request = $request->withToken($accessToken);
                }

                $response = $request->post(
                    config('services.expo.push_url'),
                    $tokenChunk
                        ->map(fn (string $token): array => $message->toPayload($token))
                        ->all(),
                );

                if ($response->failed()) {
                    Log::warning('Expo push notification request failed.', [
                        'status' => $response->status(),
                        'response' => $response->json(),
                    ]);
                }
            });
    }
}

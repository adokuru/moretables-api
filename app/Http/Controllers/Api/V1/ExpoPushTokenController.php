<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\RevokeExpoPushTokenRequest;
use App\Http\Requests\Public\StoreExpoPushTokenRequest;
use App\Http\Resources\ExpoPushTokenResource;
use App\Models\ExpoPushToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ExpoPushTokenController extends Controller
{
    public function store(StoreExpoPushTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        if ($validated['device_id'] ?? null) {
            $user->expoPushTokens()
                ->where('device_id', $validated['device_id'])
                ->where('expo_token', '!=', $validated['expo_token'])
                ->delete();
        }

        $expoPushToken = ExpoPushToken::query()->updateOrCreate(
            ['expo_token' => $validated['expo_token']],
            [
                'user_id' => $user->id,
                'device_id' => $validated['device_id'] ?? null,
                'device_name' => $validated['device_name'] ?? null,
                'platform' => $validated['platform'],
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json([
            'message' => $expoPushToken->wasRecentlyCreated
                ? 'Expo push token registered successfully.'
                : 'Expo push token updated successfully.',
            'expo_push_token' => ExpoPushTokenResource::make($expoPushToken),
        ], $expoPushToken->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(RevokeExpoPushTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $deleted = $user->expoPushTokens()
            ->where('expo_token', $request->string('expo_token')->toString())
            ->delete();

        return response()->json([
            'message' => $deleted > 0
                ? 'Expo push token revoked successfully.'
                : 'Expo push token was already inactive.',
        ]);
    }
}

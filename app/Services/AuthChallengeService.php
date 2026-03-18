<?php

namespace App\Services;

use App\AuthChallengeStatus;
use App\AuthChallengeType;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Notifications\AuthChallengeCodeNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthChallengeService
{
    public function create(User $user, AuthChallengeType $type, array $meta = [], int $ttlMinutes = 10): AuthChallenge
    {
        return DB::transaction(function () use ($user, $type, $meta, $ttlMinutes): AuthChallenge {
            AuthChallenge::query()
                ->where('user_id', $user->id)
                ->where('type', $type->value)
                ->where('status', AuthChallengeStatus::Pending->value)
                ->update(['status' => AuthChallengeStatus::Cancelled->value]);

            $code = $this->generateCode();

            $challenge = AuthChallenge::query()->create([
                'user_id' => $user->id,
                'type' => $type->value,
                'status' => AuthChallengeStatus::Pending->value,
                'challenge_token' => (string) Str::uuid(),
                'code_hash' => Hash::make($code),
                'code_expires_at' => now()->addMinutes($ttlMinutes),
                'attempts' => 0,
                'max_attempts' => 5,
                'last_sent_at' => now(),
                'meta' => $meta,
            ]);

            $user->notify(new AuthChallengeCodeNotification(
                code: $code,
                purpose: $type === AuthChallengeType::GuestSignup ? 'verify your email' : 'finish signing in',
                expiresInMinutes: $ttlMinutes,
            ));

            return $challenge;
        });
    }

    public function resend(AuthChallenge $challenge, int $ttlMinutes = 10): AuthChallenge
    {
        if ($challenge->status !== AuthChallengeStatus::Pending) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This challenge can no longer be resent.'],
            ]);
        }

        $code = $this->generateCode();

        $challenge->forceFill([
            'code_hash' => Hash::make($code),
            'code_expires_at' => now()->addMinutes($ttlMinutes),
            'attempts' => 0,
            'last_sent_at' => now(),
        ])->save();

        $challenge->user->notify(new AuthChallengeCodeNotification(
            code: $code,
            purpose: $challenge->type === AuthChallengeType::GuestSignup ? 'verify your email' : 'finish signing in',
            expiresInMinutes: $ttlMinutes,
        ));

        return $challenge->refresh();
    }

    public function verify(string $challengeToken, string $code, AuthChallengeType $type): AuthChallenge
    {
        $challenge = AuthChallenge::query()
            ->where('challenge_token', $challengeToken)
            ->where('type', $type)
            ->firstOrFail();

        if ($challenge->status !== AuthChallengeStatus::Pending) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This verification challenge is no longer active.'],
            ]);
        }

        if ($challenge->code_expires_at->isPast()) {
            $challenge->update(['status' => AuthChallengeStatus::Expired]);

            throw ValidationException::withMessages([
                'code' => ['This code has expired.'],
            ]);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            if ($challenge->attempts >= $challenge->max_attempts) {
                $challenge->update(['status' => AuthChallengeStatus::Cancelled]);
            }

            throw ValidationException::withMessages([
                'code' => ['The verification code is invalid.'],
            ]);
        }

        $challenge->forceFill([
            'status' => AuthChallengeStatus::Verified->value,
            'consumed_at' => now(),
        ])->save();

        return $challenge->refresh();
    }

    protected function generateCode(): string
    {
        if (app()->environment('testing')) {
            return '1234';
        }

        return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
}

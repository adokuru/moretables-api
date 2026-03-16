<?php

namespace App\Http\Controllers\Api\V1;

use App\AuthChallengeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GuestCompleteProfileRequest;
use App\Http\Requests\Auth\GuestStartRequest;
use App\Http\Requests\Auth\ResendChallengeRequest;
use App\Http\Requests\Auth\VerifyChallengeRequest;
use App\Http\Resources\UserResource;
use App\Models\AuthChallenge;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuthChallengeService;
use App\UserAuthMethod;
use App\UserStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Guest Auth', weight: 12)]
class GuestAuthController extends Controller
{
    public function __construct(protected AuthChallengeService $authChallengeService) {}

    public function start(GuestStartRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('email')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => null,
            'status' => UserStatus::PendingEmailVerification,
            'auth_method' => UserAuthMethod::Passwordless,
        ]);

        $this->assignCustomerRole($user);

        $challenge = $this->authChallengeService->create($user, AuthChallengeType::GuestSignup, [
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Verification code sent.',
            'challenge_token' => $challenge->challenge_token,
            'expires_at' => $challenge->code_expires_at->toIso8601String(),
        ], 201);
    }

    public function verifyOtp(VerifyChallengeRequest $request): JsonResponse
    {
        $challenge = $this->authChallengeService->verify(
            challengeToken: $request->string('challenge_token')->toString(),
            code: $request->string('code')->toString(),
            type: AuthChallengeType::GuestSignup,
        );

        $user = $challenge->user;
        $user->forceFill([
            'email_verified_at' => now(),
            'status' => UserStatus::PendingProfileCompletion,
        ])->save();

        $token = $user->createToken($request->input('device_name', 'guest-onboarding'))->plainTextToken;

        return response()->json([
            'message' => 'Email verified.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function completeProfile(GuestCompleteProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'name' => $request->string('first_name')->toString().' '.$request->string('last_name')->toString(),
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'phone' => $request->string('phone')->toString(),
            'status' => UserStatus::Active,
            'last_active_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Profile completed.',
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function resendOtp(ResendChallengeRequest $request): JsonResponse
    {
        $challenge = AuthChallenge::query()
            ->where('challenge_token', $request->string('challenge_token')->toString())
            ->firstOrFail();

        $this->authChallengeService->resend($challenge);

        return response()->json([
            'message' => 'A new verification code has been sent.',
        ]);
    }

    protected function assignCustomerRole(User $user): void
    {
        $roleId = Role::query()->where('name', Role::Customer)->value('id');

        if (! $roleId) {
            return;
        }

        UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'organization_id' => null,
            'restaurant_id' => null,
        ], [
            'scope_type' => null,
            'assigned_by' => $user->id,
        ]);
    }
}

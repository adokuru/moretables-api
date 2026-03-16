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
use Illuminate\Validation\ValidationException;

#[Group('Customer Auth', weight: 10)]
class GuestAuthController extends Controller
{
    public function __construct(protected AuthChallengeService $authChallengeService) {}

    public function start(GuestStartRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = User::query()->where('email', $email)->first();

        if ($user?->requiresAdminLogin()) {
            throw ValidationException::withMessages([
                'email' => ['Use the admin login endpoint for this account.'],
            ]);
        }

        if ($user?->requiresStaffLogin()) {
            throw ValidationException::withMessages([
                'email' => ['Use the staff login endpoint for this account.'],
            ]);
        }

        if ($user?->status === UserStatus::Suspended) {
            throw ValidationException::withMessages([
                'email' => ['This account is suspended. Contact support for help.'],
            ]);
        }

        if (! $user) {
            $user = User::query()->create([
                'name' => $email,
                'email' => $email,
                'password' => null,
                'status' => UserStatus::PendingEmailVerification,
                'auth_method' => UserAuthMethod::Passwordless,
            ]);
        } else {
            $user->forceFill([
                'auth_method' => UserAuthMethod::Passwordless,
            ])->save();
        }

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
        $needsProfileCompletion = blank($user->first_name) || blank($user->last_name) || blank($user->phone);

        $user->forceFill([
            'email_verified_at' => $user->email_verified_at ?? now(),
            'status' => $needsProfileCompletion ? UserStatus::PendingProfileCompletion : UserStatus::Active,
            'auth_method' => UserAuthMethod::Passwordless,
            'last_active_at' => now(),
        ])->save();

        $token = $user->createToken($request->input('device_name', 'customer-api'))->plainTextToken;

        return response()->json([
            'message' => $needsProfileCompletion ? 'Email verified.' : 'Login successful.',
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
            'auth_method' => UserAuthMethod::Passwordless,
            'last_active_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Profile completed.',
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        return response()->json([
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function logout(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $user->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    public function resendOtp(ResendChallengeRequest $request): JsonResponse
    {
        $challenge = AuthChallenge::query()
            ->where('challenge_token', $request->string('challenge_token')->toString())
            ->where('type', AuthChallengeType::GuestSignup)
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

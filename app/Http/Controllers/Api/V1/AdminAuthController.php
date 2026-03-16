<?php

namespace App\Http\Controllers\Api\V1;

use App\AuthChallengeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Requests\Auth\VerifyChallengeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthChallengeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

#[Group('Admin Auth', weight: 48)]
class AdminAuthController extends Controller
{
    public function __construct(protected AuthChallengeService $authChallengeService) {}

    public function login(StaffLoginRequest $request): JsonResponse
    {
        $user = $this->findUserByIdentifier($request->string('identifier')->toString());

        if (! $user || ! $user->password || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['Invalid credentials.'],
            ]);
        }

        if ($user->requiresStaffLogin()) {
            throw ValidationException::withMessages([
                'identifier' => ['Use the staff login endpoint for this account.'],
            ]);
        }

        if (! $user->requiresAdminLogin()) {
            throw ValidationException::withMessages([
                'identifier' => ['This account does not require admin login.'],
            ]);
        }

        $challenge = $this->authChallengeService->create($user, AuthChallengeType::AdminLogin, [
            'identifier' => $request->string('identifier')->toString(),
        ]);

        return response()->json([
            'message' => 'A verification code has been sent to your email address.',
            'challenge_token' => $challenge->challenge_token,
            'expires_at' => $challenge->code_expires_at->toIso8601String(),
        ]);
    }

    public function verify(VerifyChallengeRequest $request): JsonResponse
    {
        $challenge = $this->authChallengeService->verify(
            challengeToken: $request->string('challenge_token')->toString(),
            code: $request->string('code')->toString(),
            type: AuthChallengeType::AdminLogin,
        );

        $user = $challenge->user->load('roles');
        abort_unless($user->requiresAdminLogin(), 403);

        $user->forceFill(['last_active_at' => now()])->save();

        $token = $user->createToken($request->input('device_name', 'admin-api'))->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user),
        ]);
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        abort_unless($user->requiresAdminLogin(), 403);

        return response()->json([
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function logout(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        abort_unless($user->requiresAdminLogin(), 403);

        $user->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    protected function findUserByIdentifier(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();
    }
}

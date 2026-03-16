<?php

namespace App\Http\Controllers\Api\V1;

use App\AuthChallengeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Requests\Auth\VerifyChallengeRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuthChallengeService;
use App\UserAuthMethod;
use App\UserStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Group('Customer Auth', weight: 10)]
class AuthController extends Controller
{
    public function __construct(protected AuthChallengeService $authChallengeService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->create([
            'name' => $validated['first_name'].' '.$validated['last_name'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => $validated['password'],
            'status' => UserStatus::Active,
            'auth_method' => UserAuthMethod::Password,
            'email_verified_at' => now(),
            'last_active_at' => now(),
        ]);

        $this->assignRole($user, Role::Customer);

        $token = $user->createToken($request->input('device_name', 'customer-api'))->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user->load('roles')),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->findUserByIdentifier($request->string('identifier')->toString());

        if (! $user || ! $user->password || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['Invalid credentials.'],
            ]);
        }

        if ($user->requiresAdminLogin()) {
            throw ValidationException::withMessages([
                'identifier' => ['Use the admin login endpoint for this account.'],
            ]);
        }

        if ($user->requiresStaffLogin()) {
            throw ValidationException::withMessages([
                'identifier' => ['Use the staff login endpoint for this account.'],
            ]);
        }

        $user->forceFill(['last_active_at' => now()])->save();

        $token = $user->createToken($request->input('device_name', 'customer-api'))->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function staffLogin(StaffLoginRequest $request): JsonResponse
    {
        $user = $this->findUserByIdentifier($request->string('identifier')->toString());

        if (! $user || ! $user->password || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => ['Invalid credentials.'],
            ]);
        }

        if ($user->requiresAdminLogin()) {
            throw ValidationException::withMessages([
                'identifier' => ['Use the admin login endpoint for this account.'],
            ]);
        }

        if (! $user->requiresStaffLogin()) {
            throw ValidationException::withMessages([
                'identifier' => ['This account does not require staff login.'],
            ]);
        }

        $challenge = $this->authChallengeService->create($user, AuthChallengeType::StaffLogin, [
            'identifier' => $request->string('identifier')->toString(),
        ]);

        return response()->json([
            'message' => 'A verification code has been sent to your email address.',
            'challenge_token' => $challenge->challenge_token,
            'expires_at' => $challenge->code_expires_at->toIso8601String(),
        ]);
    }

    public function verifyStaffLogin(VerifyChallengeRequest $request): JsonResponse
    {
        $challenge = $this->authChallengeService->verify(
            challengeToken: $request->string('challenge_token')->toString(),
            code: $request->string('code')->toString(),
            type: AuthChallengeType::StaffLogin,
        );

        $user = $challenge->user->load('roles');
        $user->forceFill(['last_active_at' => now()])->save();

        $token = $user->createToken($request->input('device_name', 'staff-api'))->plainTextToken;

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

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->safe()->only('email'));

        return response()->json([
            'message' => 'If the account exists, a reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'auth_method' => UserAuthMethod::Password,
                    'status' => UserStatus::Active,
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => __($status),
        ]);
    }

    protected function findUserByIdentifier(string $identifier): ?User
    {
        return User::query()
            ->where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();
    }

    protected function assignRole(User $user, string $roleName): void
    {
        $roleId = Role::query()->where('name', $roleName)->value('id');

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

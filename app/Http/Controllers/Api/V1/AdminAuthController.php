<?php

namespace App\Http\Controllers\Api\V1;

use App\AuthChallengeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StaffLoginRequest;
use App\Http\Requests\Auth\UpdateProfileSettingsRequest;
use App\Http\Requests\Auth\VerifyChallengeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\AuthChallengeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

#[Group('Admin Auth', weight: 48)]
class AdminAuthController extends Controller
{
    public function __construct(
        protected AuthChallengeService $authChallengeService,
        protected AuditLogService $auditLogService,
    ) {}

    public function login(StaffLoginRequest $request): JsonResponse
    {
        $identifier = $request->string('identifier')->toString();
        $user = $this->findUserByIdentifier($identifier);

        if (! $user || ! $user->password || ! Hash::check($request->string('password')->toString(), $user->password)) {
            $this->logAdminLoginFailure($request, $user, $identifier, 'invalid_credentials');

            throw ValidationException::withMessages([
                'identifier' => ['Invalid credentials.'],
            ]);
        }

        if (! $user->isActive()) {
            $this->logAdminLoginFailure($request, $user, $identifier, 'inactive_account');

            throw ValidationException::withMessages([
                'identifier' => ['This admin account is not currently active.'],
            ]);
        }

        if ($user->requiresStaffLogin()) {
            $this->logAdminLoginFailure($request, $user, $identifier, 'wrong_endpoint_staff');

            throw ValidationException::withMessages([
                'identifier' => ['Use the staff login endpoint for this account.'],
            ]);
        }

        if (! $user->requiresAdminLogin()) {
            $this->logAdminLoginFailure($request, $user, $identifier, 'not_admin');

            throw ValidationException::withMessages([
                'identifier' => ['This account does not require admin login.'],
            ]);
        }

        $challenge = $this->authChallengeService->create($user, AuthChallengeType::AdminLogin, [
            'identifier' => $identifier,
        ]);

        Log::info('Admin login challenge created', [
            'challenge' => $challenge,
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

        $deviceName = $request->input('device_name', 'admin-api');

        $token = $user->createToken(
            $deviceName,
            ['*'],
            now()->addMinutes((int) config('sanctum.admin_expiration')),
        )->plainTextToken;

        $this->auditLogService->logAdmin(
            action: 'auth.login',
            actor: $user,
            auditable: $user,
            newValues: $this->adminLoginContext($request, $user, [
                'outcome' => 'success',
                'device_name' => $deviceName,
                'logged_in_at' => now()->toIso8601String(),
            ]),
            description: 'Signed in to the admin dashboard.',
        );

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

    public function profile(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        abort_unless($user->requiresAdminLogin(), 403);

        return response()->json([
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    public function updateProfile(UpdateProfileSettingsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->requiresAdminLogin(), 403);

        $validated = $request->validated();
        $user->fill($validated);

        if (array_key_exists('first_name', $validated) || array_key_exists('last_name', $validated)) {
            $user->name = trim(implode(' ', array_filter([
                $validated['first_name'] ?? $user->first_name,
                $validated['last_name'] ?? $user->last_name,
            ])));
        }

        $user->save();

        $this->logAdminAudit($request, 'profile.updated', $user);

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => UserResource::make($user->refresh()->load('roles')),
        ]);
    }

    public function logout(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        abort_unless($user->requiresAdminLogin(), 403);

        $request = request();

        $this->logAdminAudit(
            $request,
            'auth.logout',
            $user,
            oldValues: null,
            newValues: $this->adminLoginContext($request, $user, [
                'outcome' => 'logout',
                'logged_out_at' => now()->toIso8601String(),
            ]),
            description: 'Signed out of the admin dashboard.',
        );

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

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function adminLoginContext(Request $request, ?User $user, array $extra = []): array
    {
        $userAgent = $request->userAgent();

        return array_filter([
            'email' => $user?->email,
            'roles' => $user?->relationLoaded('roles')
                ? $user->roles->pluck('name')->values()->all()
                : $user?->roles()->pluck('name')->values()->all(),
            'ip_address' => $request->ip(),
            'user_agent' => $userAgent,
            'platform' => $this->guessPlatform($userAgent),
            'browser' => $this->guessBrowser($userAgent),
            ...$extra,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    protected function logAdminLoginFailure(
        Request $request,
        ?User $user,
        string $identifier,
        string $reason,
    ): void {
        $this->auditLogService->log(
            action: 'admin.auth.login_failed',
            actor: $user,
            auditable: $user,
            newValues: $this->adminLoginContext($request, $user, [
                'outcome' => 'failed',
                'reason' => $reason,
                'identifier' => $identifier,
                'attempted_at' => now()->toIso8601String(),
            ]),
            description: 'Failed admin dashboard login attempt.',
        );
    }

    protected function guessPlatform(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS') => 'macOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown',
        };
    }

    protected function guessBrowser(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'Chrome/') && ! str_contains($userAgent, 'Edg/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') && ! str_contains($userAgent, 'Chrome/') => 'Safari',
            default => 'Unknown',
        };
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\AuthChallengeStatus;
use App\AuthChallengeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ResendChallengeRequest;
use App\Http\Requests\Auth\RestoreAccountRequest;
use App\Http\Requests\Auth\UpdatePhoneRequest;
use App\Http\Requests\Auth\UpdateProfileSettingsRequest;
use App\Http\Requests\Auth\UploadProfilePictureRequest;
use App\Http\Requests\Auth\VerifyChallengeRequest;
use App\Http\Resources\MediaAssetResource;
use App\Http\Resources\UserResource;
use App\Models\AuthChallenge;
use App\Models\User;
use App\Services\AuthChallengeService;
use App\Services\MediaLibraryService;
use App\Services\RewardProgramService;
use App\UserStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Group('Customer Auth', weight: 11)]
class ProfileSettingsController extends Controller
{
    public function __construct(
        protected MediaLibraryService $mediaLibraryService,
        protected RewardProgramService $rewardProgramService,
        protected AuthChallengeService $authChallengeService,
    ) {}

    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $rewardStatus = $this->rewardProgramService->statusForUser($user);

        return response()->json([
            'user' => UserResource::make($user->load([
                'roles',
                'media',
                'allergies',
                'roleAssignments.restaurant.activeBillingSubscription.plan',
                'roleAssignments.restaurant.organization.activeBillingSubscription.plan',
                'roleAssignments.restaurant.latestBillingSubscription.plan',
            ])),
            'rewards' => [
                'points' => $rewardStatus['points'],
                'current_level' => $rewardStatus['current_level'],
                'points_to_next_level' => $rewardStatus['points_to_next_level'],
                'progress_percentage' => $rewardStatus['progress_percentage'],
            ],
        ]);
    }

    public function update(UpdateProfileSettingsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $user->fill($validated);

        if (array_key_exists('first_name', $validated) || array_key_exists('last_name', $validated)) {
            $user->name = trim(implode(' ', array_filter([
                $validated['first_name'] ?? $user->first_name,
                $validated['last_name'] ?? $user->last_name,
            ])));
        }

        $user->save();

        if (array_key_exists('allergies', $validated)) {
            $user->allergies()->delete();

            if (! empty($validated['allergies'])) {
                $user->allergies()->createMany(
                    collect($validated['allergies'])
                        ->unique()
                        ->map(fn (string $name): array => ['name' => $name])
                        ->all()
                );
            }
        }

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => UserResource::make($user->refresh()->load(['roles', 'media', 'allergies'])),
        ]);
    }

    /**
     * Update the authenticated customer's phone number.
     */
    public function updatePhone(UpdatePhoneRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill(['phone' => $request->validated('phone')])->save();

        return response()->json([
            'message' => 'Phone number updated successfully.',
            'user' => UserResource::make($user->refresh()->load(['roles', 'media', 'allergies'])),
        ]);
    }

    /**
     * Change the authenticated customer's password.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill(['password' => $request->validated('password')])->save();

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    public function updateProfilePicture(UploadProfilePictureRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $profilePicture = $this->mediaLibraryService->addUploadedFileToCollection(
            $user,
            $request->file('profile_picture'),
            'profile_picture',
            ['alt_text' => $request->validated('alt_text')],
        );

        return response()->json([
            'message' => 'Profile picture uploaded successfully.',
            'profile_picture' => MediaAssetResource::make($profilePicture),
            'user' => UserResource::make($user->refresh()->load(['roles', 'media'])),
        ], 201);
    }

    /**
     * Request deletion of the authenticated account.
     */
    public function requestDeletion(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        DB::transaction(function () use ($user): void {
            $user->forceFill(['status' => UserStatus::PendingDeletion])->save();
            $user->authChallenges()
                ->where('status', AuthChallengeStatus::Pending)
                ->update(['status' => AuthChallengeStatus::Cancelled]);
            $user->tokens()->delete();
        });

        return response()->json([
            'message' => 'Account deletion requested successfully.',
        ]);
    }

    /**
     * Start restoring a soft-deleted (pending deletion) account by email OTP.
     */
    public function restore(RestoreAccountRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = User::query()->where('email', $email)->first();

        if (! $user || $user->status !== UserStatus::PendingDeletion) {
            throw ValidationException::withMessages([
                'email' => ['No account pending deletion was found for this email.'],
            ]);
        }

        $challenge = $this->authChallengeService->create($user, AuthChallengeType::AccountRestore, [
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'Verification code sent.',
            'challenge_token' => $challenge->challenge_token,
            'expires_at' => $challenge->code_expires_at->toIso8601String(),
        ], 201);
    }

    /**
     * Verify the restore OTP and reactivate the account.
     */
    public function confirmRestore(VerifyChallengeRequest $request): JsonResponse
    {
        $challenge = $this->authChallengeService->verify(
            challengeToken: $request->string('challenge_token')->toString(),
            code: $request->string('code')->toString(),
            type: AuthChallengeType::AccountRestore,
        );

        $user = $challenge->user;

        if ($user->status !== UserStatus::PendingDeletion) {
            throw ValidationException::withMessages([
                'challenge_token' => ['This account is not pending deletion.'],
            ]);
        }

        $needsProfileCompletion = blank($user->first_name) || blank($user->last_name);

        $user->forceFill([
            'status' => $needsProfileCompletion
                ? UserStatus::PendingProfileCompletion
                : UserStatus::Active,
            'last_active_at' => now(),
        ])->save();

        $token = $user->createToken($request->input('device_name', 'customer-api'))->plainTextToken;

        return response()->json([
            'message' => 'Account restored successfully.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user->load('roles')),
        ]);
    }

    /**
     * Resend the account restore verification code.
     */
    public function resendRestore(ResendChallengeRequest $request): JsonResponse
    {
        $challenge = AuthChallenge::query()
            ->where('challenge_token', $request->string('challenge_token')->toString())
            ->where('type', AuthChallengeType::AccountRestore)
            ->firstOrFail();

        $this->authChallengeService->resend($challenge);

        return response()->json([
            'message' => 'A new verification code has been sent.',
        ]);
    }
}

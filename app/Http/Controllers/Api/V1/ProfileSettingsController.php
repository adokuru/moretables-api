<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileSettingsRequest;
use App\Http\Requests\Auth\UploadProfilePictureRequest;
use App\Http\Resources\MediaAssetResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\MediaLibraryService;
use App\Services\RewardProgramService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Customer Auth', weight: 11)]
class ProfileSettingsController extends Controller
{
    public function __construct(
        protected MediaLibraryService $mediaLibraryService,
        protected RewardProgramService $rewardProgramService,
    ) {}

    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        $rewardStatus = $this->rewardProgramService->statusForUser($user);

        return response()->json([
            'user' => UserResource::make($user->load(['roles', 'media'])),
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

        return response()->json([
            'message' => 'Profile updated successfully.',
            'user' => UserResource::make($user->refresh()->load(['roles', 'media'])),
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
}

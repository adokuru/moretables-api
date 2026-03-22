<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRewardPointTransactionRequest;
use App\Http\Requests\Admin\UpdateRewardProgramRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\RewardProgramService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Admin Rewards', weight: 58)]
class AdminRewardProgramController extends Controller
{
    public function __construct(protected RewardProgramService $rewardProgramService) {}

    /**
     * Show the active lifetime reward program and tier thresholds.
     */
    public function show(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $program = $this->rewardProgramService->activeProgram();

        return response()->json([
            'reward_program' => $this->rewardProgramService->programPayload($program),
        ]);
    }

    /**
     * Update the active lifetime reward program and tier ranges.
     */
    public function update(UpdateRewardProgramRequest $request): JsonResponse
    {
        $program = $this->rewardProgramService->updateProgram($request->validated());

        return response()->json([
            'message' => 'Reward program updated successfully.',
            'reward_program' => $this->rewardProgramService->programPayload($program),
        ]);
    }

    /**
     * Create a point transaction for a user and recalculate their tier.
     */
    public function storePoints(StoreRewardPointTransactionRequest $request, User $user): JsonResponse
    {
        $transaction = $this->rewardProgramService->awardPoints(
            user: $user,
            attributes: $request->validated(),
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Reward points recorded successfully.',
            'transaction' => $this->rewardProgramService->transactionPayload($transaction),
            'rewards' => $this->rewardProgramService->statusForUser($user),
        ], 201);
    }

    protected function ensureAdminAccess(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole([Role::BusinessAdmin, Role::DevAdmin, Role::SuperAdmin]), 403);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\RedeemRewardPointsRequest;
use App\Services\RewardProgramService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Customer Rewards', weight: 28)]
class CustomerRewardController extends Controller
{
    public function __construct(protected RewardProgramService $rewardProgramService) {}

    /**
     * Return the authenticated customer's lifetime points and current reward tier.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'rewards' => $this->rewardProgramService->statusForUser($request->user()),
        ]);
    }

    /**
     * Return the authenticated customer's reward point transaction history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $transactions = $this->rewardProgramService->transactionsForUser(
            user: $request->user(),
            perPage: (int) $request->integer('per_page', 15),
        );

        return response()->json([
            'rewards' => $this->rewardProgramService->statusForUser($request->user()),
            'data' => $this->rewardProgramService->transactionPayloadsForCollection(
                $transactions->getCollection(),
            ),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Redeem loyalty points for restaurant credit at a configured tier.
     */
    public function redeem(RedeemRewardPointsRequest $request): JsonResponse
    {
        $transaction = $this->rewardProgramService->redeemPoints(
            user: $request->user(),
            points: (int) $request->validated('points'),
            actor: $request->user(),
        );

        return response()->json([
            'transaction' => $this->rewardProgramService->transactionPayload($transaction),
            'rewards' => $this->rewardProgramService->statusForUser($request->user()),
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreCampaignWaitlistRequest;
use App\Jobs\AppendCampaignWaitlistSignup;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Campaign Waitlist', weight: 6)]
class CampaignWaitlistController extends Controller
{
    /**
     * Join the campaign waitlist.
     *
     * Queues the Google Sheets append and confirmation email for background processing.
     */
    #[Response(202, description: 'The waitlist signup was queued for processing.')]
    public function store(StoreCampaignWaitlistRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        AppendCampaignWaitlistSignup::dispatch($email, now());

        return response()->json([
            'message' => 'Your waitlist signup has been accepted and will be processed shortly.',
        ], 202);
    }
}

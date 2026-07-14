<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\GoogleSheetsWaitlistException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreCampaignWaitlistRequest;
use App\Notifications\CampaignWaitlistConfirmationNotification;
use App\Services\GoogleSheetsWaitlistService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;

#[Group('Campaign Waitlist', weight: 6)]
class CampaignWaitlistController extends Controller
{
    /**
     * Join the campaign waitlist.
     *
     * Appends the subscriber to the configured Google Sheet and queues a confirmation email.
     */
    #[Response(201, description: 'The email was added to the waitlist.')]
    #[Response(503, description: 'The waitlist storage service is temporarily unavailable.')]
    public function store(
        StoreCampaignWaitlistRequest $request,
        GoogleSheetsWaitlistService $googleSheets,
    ): JsonResponse {
        $email = $request->validated('email');
        $joinedAt = now();

        try {
            $googleSheets->append($email, $joinedAt);
        } catch (GoogleSheetsWaitlistException $exception) {
            report($exception);

            return response()->json([
                'message' => 'We could not add you to the waitlist right now. Please try again shortly.',
            ], 503);
        }

        Notification::route('mail', $email)
            ->notify(new CampaignWaitlistConfirmationNotification);

        return response()->json([
            'message' => 'You have joined the waitlist. Please check your email for confirmation.',
        ], 201);
    }
}

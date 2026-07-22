<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitGuestSurveyResponseRequest;
use App\Http\Resources\GuestSurveyResource;
use App\Models\GuestSurveyInvitation;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[Group('Guest Surveys', weight: 8)]
class PublicGuestSurveyController extends Controller
{
    #[Response(200, type: 'array{survey: array{id: int, version: int, publication_sequence: int, title: string, description: string|null, logo_url: string|null, status: string, questions: list<array{id: string, type: string, prompt: string, required: bool, options: list<string>}>, settings: array{send_delay_minutes: int, channels: list<string>}, restaurant: array{id: int, name: string, slug: string}, published_at: string|null}, already_submitted: bool, expires_at: string}')]
    #[Response(404, type: 'array{message: string}')]
    public function show(Request $request, string $token): JsonResponse
    {
        $invitation = $this->resolveInvitation($token);

        return response()->json([
            'survey' => GuestSurveyResource::make($invitation->survey->load('restaurant'))->resolve($request),
            'already_submitted' => $invitation->response()->exists(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    #[Response(201, type: 'array{message: string}')]
    #[Response(404, type: 'array{message: string}')]
    #[Response(422, type: 'array{message: string, errors: array<string, list<string>>}')]
    public function store(SubmitGuestSurveyResponseRequest $request, string $token): JsonResponse
    {
        $invitation = $this->resolveInvitation($token);

        $response = $invitation->response()->createOrFirst(
            ['guest_survey_invitation_id' => $invitation->id],
            ['answers' => $request->validated('answers'), 'submitted_at' => now()],
        );

        if (! $response->wasRecentlyCreated) {
            throw ValidationException::withMessages(['survey' => ['This survey has already been submitted.']]);
        }

        return response()->json(['message' => 'Survey response submitted successfully.'], 201);
    }

    private function resolveInvitation(string $token): GuestSurveyInvitation
    {
        $invitation = GuestSurveyInvitation::query()
            ->with('survey')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        abort_if($invitation === null, 404, 'Survey invitation not found or expired.');

        return $invitation;
    }
}

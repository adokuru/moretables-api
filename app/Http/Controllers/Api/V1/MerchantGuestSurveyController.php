<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreGuestSurveyRequest;
use App\Http\Requests\Merchant\UpdateGuestSurveyRequest;
use App\Http\Resources\GuestSurveyResource;
use App\Http\Resources\GuestSurveyResponseResource;
use App\Models\GuestSurvey;
use App\Models\GuestSurveyResponse;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Merchant Guest Surveys', weight: 39)]
class MerchantGuestSurveyController extends Controller
{
    #[Response(200, type: 'array{data: list<array{key: string, title: string, description: string, questions: list<array{id: string, type: string, prompt: string, required: bool, options: list<string>}>}>}')]
    public function templates(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.view', 'communications.manage'], $restaurant), 403);

        return response()->json(['data' => [
            [
                'key' => 'post_dining',
                'title' => 'Post Dining Questions',
                'description' => 'Standard post-visit feedback survey.',
                'questions' => $this->postDiningQuestions(),
            ],
            ['key' => 'blank', 'title' => 'Blank Template', 'description' => 'Build your own survey.', 'questions' => []],
        ]]);
    }

    #[Response(200, type: 'array{data: list<array{id: int, version: int, publication_sequence: int|null, title: string, description: string|null, logo_url: string|null, status: string, questions: list<array{id: string, type: string, prompt: string, required: bool, options: list<string>}>, settings: array{send_delay_minutes: int, channels: list<string>}, published_at: string|null}>}')]
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.view', 'communications.manage'], $restaurant), 403);

        return response()->json([
            'data' => GuestSurveyResource::collection($restaurant->guestSurveys()->latest()->get())->resolve($request),
        ]);
    }

    public function store(StoreGuestSurveyRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.manage', 'communications.manage'], $restaurant), 403);

        $validated = $request->validated();
        $survey = DB::transaction(function () use ($restaurant, $validated): GuestSurvey {
            $lockedRestaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);

            return $lockedRestaurant->guestSurveys()->create([
                ...collect($validated)->except('template_key')->all(),
                'version' => ((int) $lockedRestaurant->guestSurveys()->max('version')) + 1,
                'status' => 'draft',
                'questions' => $validated['questions'] ?? (($validated['template_key'] ?? 'blank') === 'post_dining' ? $this->postDiningQuestions() : []),
                'channels' => $validated['channels'] ?? ['push', 'email'],
                'send_delay_minutes' => $validated['send_delay_minutes'] ?? 120,
            ]);
        });

        return response()->json([
            'message' => 'Guest survey created successfully.',
            'survey' => GuestSurveyResource::make($survey->load('restaurant'))->resolve($request),
        ], 201);
    }

    public function show(Request $request, Restaurant $restaurant, GuestSurvey $survey): JsonResponse
    {
        $this->assertSurveyBelongsToRestaurant($survey, $restaurant);
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.view', 'communications.manage'], $restaurant), 403);

        return response()->json(['survey' => GuestSurveyResource::make($survey->load('restaurant'))->resolve($request)]);
    }

    public function update(UpdateGuestSurveyRequest $request, Restaurant $restaurant, GuestSurvey $survey): JsonResponse
    {
        $this->assertSurveyBelongsToRestaurant($survey, $restaurant);
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.manage', 'communications.manage'], $restaurant), 403);

        $survey = DB::transaction(function () use ($restaurant, $survey, $request): GuestSurvey {
            $lockedRestaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            $lockedSurvey = $lockedRestaurant->guestSurveys()->lockForUpdate()->findOrFail($survey->id);

            if ($lockedSurvey->status !== 'draft') {
                throw ValidationException::withMessages(['survey' => ['Published surveys are immutable. Create a new draft version instead.']]);
            }

            $lockedSurvey->update($request->validated());

            return $lockedSurvey;
        });

        return response()->json([
            'message' => 'Guest survey updated successfully.',
            'survey' => GuestSurveyResource::make($survey->refresh()->load('restaurant'))->resolve($request),
        ]);
    }

    public function publish(Request $request, Restaurant $restaurant, GuestSurvey $survey): JsonResponse
    {
        $this->assertSurveyBelongsToRestaurant($survey, $restaurant);
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.manage', 'communications.manage'], $restaurant), 403);

        DB::transaction(function () use ($restaurant, $survey): void {
            $lockedRestaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            $lockedSurvey = $lockedRestaurant->guestSurveys()->lockForUpdate()->findOrFail($survey->id);

            if ($lockedSurvey->status !== 'draft') {
                throw ValidationException::withMessages(['survey' => ['Only draft surveys can be published.']]);
            }

            $this->validatePublishable($lockedSurvey);
            $lockedRestaurant->guestSurveys()->where('status', 'published')->where('id', '!=', $lockedSurvey->id)->update(['status' => 'archived']);
            $lockedSurvey->update([
                'status' => 'published',
                'publication_sequence' => ((int) $lockedRestaurant->guestSurveys()->max('publication_sequence')) + 1,
                'published_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Guest survey published successfully.',
            'survey' => GuestSurveyResource::make($survey->refresh()->load('restaurant'))->resolve($request),
        ]);
    }

    #[Response(200, type: 'array{data: list<array{id: int, diner: string, visit_date: string|null, reservation_id: int, answers: list<array{question_id: string, value: int|bool|string}>, submitted_at: string}>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}')]
    public function responses(Request $request, Restaurant $restaurant, GuestSurvey $survey): JsonResponse
    {
        $this->assertSurveyBelongsToRestaurant($survey, $restaurant);
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.view', 'communications.manage'], $restaurant), 403);

        $responses = GuestSurveyResponse::query()
            ->whereHas('invitation', fn ($query) => $query->where('guest_survey_id', $survey->id))
            ->with(['invitation.reservation.user', 'invitation.reservation.guestContact'])
            ->latest('submitted_at')
            ->paginate($this->perPage($request, 20, 100));

        return response()->json([
            'data' => GuestSurveyResponseResource::collection($responses->getCollection())->resolve($request),
            'meta' => ['current_page' => $responses->currentPage(), 'last_page' => $responses->lastPage(), 'per_page' => $responses->perPage(), 'total' => $responses->total()],
        ]);
    }

    public function exportResponses(Request $request, Restaurant $restaurant, GuestSurvey $survey): StreamedResponse
    {
        $this->assertSurveyBelongsToRestaurant($survey, $restaurant);
        // Export is gated by reporting.export specifically, not restaurants.view/
        // communications.manage — viewing and exporting are meant to be distinct
        // capabilities (see docs/PERMISSION_MATRIX.md). restaurants.manage kept
        // as the usual super-permission fallback.
        abort_unless($request->user()->hasAnyRestaurantPermission(['reporting.export', 'restaurants.manage'], $restaurant), 403);

        $responses = GuestSurveyResponse::query()
            ->whereHas('invitation', fn ($query) => $query->where('guest_survey_id', $survey->id))
            ->with(['invitation.reservation.user', 'invitation.reservation.guestContact'])
            ->latest('submitted_at')
            ->get();

        $rows = GuestSurveyResponseResource::collection($responses)->resolve($request);

        return $this->csvResponse(
            Str::slug($survey->title ?: 'survey').'-responses.csv',
            $this->responseRowsToCsv($survey->questions, $rows),
        );
    }

    public function destroy(Request $request, Restaurant $restaurant, GuestSurvey $survey): JsonResponse
    {
        $this->assertSurveyBelongsToRestaurant($survey, $restaurant);
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.manage', 'communications.manage'], $restaurant), 403);

        DB::transaction(function () use ($restaurant, $survey): void {
            $lockedRestaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            $lockedSurvey = $lockedRestaurant->guestSurveys()->lockForUpdate()->findOrFail($survey->id);

            if ($lockedSurvey->status !== 'draft' || $lockedSurvey->invitations()->exists()) {
                throw ValidationException::withMessages(['survey' => ['Only unreferenced draft surveys can be deleted.']]);
            }

            $lockedSurvey->delete();
        });

        return response()->json(['message' => 'Guest survey deleted successfully.']);
    }

    /** @return list<array{id: string, type: string, prompt: string, required: bool, options: list<string>}> */
    private function postDiningQuestions(): array
    {
        return [
            ['id' => 'food', 'type' => 'rating', 'prompt' => 'How would you rate your meal?', 'required' => true, 'options' => []],
            ['id' => 'service', 'type' => 'rating', 'prompt' => 'How would you rate the service?', 'required' => true, 'options' => []],
            ['id' => 'clean', 'type' => 'yes_no', 'prompt' => 'Was the restaurant clean?', 'required' => true, 'options' => []],
            ['id' => 'nps', 'type' => 'nps', 'prompt' => 'How likely are you to recommend us?', 'required' => true, 'options' => []],
            ['id' => 'comments', 'type' => 'long_text', 'prompt' => 'Is there anything else we could improve?', 'required' => false, 'options' => []],
        ];
    }

    private function csvResponse(string $filename, string $content): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * @param  list<array{id: string, type: string, prompt: string, required: bool, options: list<string>}>  $questions
     * @param  array<int, array{id: int, diner: string, visit_date: string|null, reservation_id: int, answers: list<array{question_id: string, value: mixed}>, submitted_at: string|null}>  $rows
     */
    private function responseRowsToCsv(array $questions, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [
            'Diner',
            'Visit Date',
            ...array_map(fn (array $question): string => $question['prompt'], $questions),
            'Submitted At',
        ]);

        foreach ($rows as $row) {
            $answersByQuestion = collect($row['answers'])->keyBy('question_id');

            fputcsv($handle, [
                $row['diner'],
                $row['visit_date'] ?? '',
                ...array_map(
                    fn (array $question): string => $this->formatSurveyAnswerForCsv(
                        $question['type'],
                        $answersByQuestion->get($question['id'])['value'] ?? null,
                    ),
                    $questions,
                ),
                $row['submitted_at'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function formatSurveyAnswerForCsv(string $questionType, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($questionType === 'yes_no') {
            if (in_array($value, [true, 1, '1', 'true', 'Yes'], true)) {
                return 'Yes';
            }
            if (in_array($value, [false, 0, '0', 'false', 'No'], true)) {
                return 'No';
            }
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    private function assertSurveyBelongsToRestaurant(GuestSurvey $survey, Restaurant $restaurant): void
    {
        abort_unless($survey->restaurant_id === $restaurant->id, 404);
    }

    private function validatePublishable(GuestSurvey $survey): void
    {
        if ($survey->questions === []) {
            throw ValidationException::withMessages(['questions' => ['Add at least one question before publishing.']]);
        }

        foreach ($survey->questions as $index => $question) {
            if (($question['type'] ?? null) === 'single_choice'
                && count(array_unique(array_filter($question['options'] ?? [], fn (mixed $option): bool => is_string($option) && trim($option) !== ''))) < 2) {
                throw ValidationException::withMessages(["questions.{$index}.options" => ['Single-choice questions require at least two unique nonblank options.']]);
            }
        }
    }
}

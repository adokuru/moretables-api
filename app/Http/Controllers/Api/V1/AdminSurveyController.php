<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendAdminSurveyRequest;
use App\Http\Requests\Admin\StoreAdminSurveyRequest;
use App\Http\Requests\Admin\UpdateAdminSurveyRequest;
use App\Http\Resources\GuestSurveyResource;
use App\Jobs\DispatchAdminSurveyJob;
use App\Models\AdminSurveyDispatch;
use App\Models\GuestSurvey;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Group('Admin Surveys', weight: 50)]
class AdminSurveyController extends Controller
{
    #[QueryParameter('scope', description: 'Filter by scope: platform or restaurant', type: 'string')]
    #[QueryParameter('status', description: 'Filter by status: draft, published, archived', type: 'string')]
    #[Response(200, type: 'array{data: list<array{id: int, scope: string, title: string, status: string, questions: list<mixed>, published_at: string|null, created_at: string}>}')]
    public function index(Request $request): JsonResponse
    {
        $surveys = GuestSurvey::query()
            ->with('restaurant')
            ->when($request->input('scope'), fn ($query, $scope) => $query->where('scope', $scope))
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate($this->perPage($request, 20, 100));

        return response()->json([
            'data' => GuestSurveyResource::collection($surveys->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $surveys->currentPage(),
                'last_page' => $surveys->lastPage(),
                'per_page' => $surveys->perPage(),
                'total' => $surveys->total(),
            ],
        ]);
    }

    public function store(StoreAdminSurveyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $survey = DB::transaction(function () use ($validated): GuestSurvey {
            $restaurantId = $validated['restaurant_id'] ?? null;

            $versionQuery = GuestSurvey::query()
                ->when(
                    $restaurantId !== null,
                    fn ($q) => $q->where('restaurant_id', $restaurantId),
                    fn ($q) => $q->whereNull('restaurant_id'),
                );

            return GuestSurvey::query()->create([
                'scope' => $validated['scope'],
                'restaurant_id' => $restaurantId,
                'version' => ((int) $versionQuery->max('version')) + 1,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'logo_url' => $validated['logo_url'] ?? null,
                'status' => 'draft',
                'questions' => $validated['questions'] ?? [],
                'channels' => $validated['channels'] ?? ['push', 'email'],
                'send_delay_minutes' => 0,
            ]);
        });

        return response()->json([
            'message' => 'Survey created successfully.',
            'survey' => GuestSurveyResource::make($survey->load('restaurant'))->resolve($request),
        ], 201);
    }

    public function show(Request $request, GuestSurvey $survey): JsonResponse
    {
        return response()->json([
            'survey' => GuestSurveyResource::make($survey->load(['restaurant', 'adminDispatches']))->resolve($request),
        ]);
    }

    public function update(UpdateAdminSurveyRequest $request, GuestSurvey $survey): JsonResponse
    {
        $survey = DB::transaction(function () use ($survey, $request): GuestSurvey {
            $locked = GuestSurvey::query()->lockForUpdate()->findOrFail($survey->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['survey' => ['Published surveys are immutable. Create a new draft instead.']]);
            }

            $locked->update($request->validated());

            return $locked;
        });

        return response()->json([
            'message' => 'Survey updated successfully.',
            'survey' => GuestSurveyResource::make($survey->refresh()->load('restaurant'))->resolve($request),
        ]);
    }

    public function publish(Request $request, GuestSurvey $survey): JsonResponse
    {
        DB::transaction(function () use ($survey): void {
            $locked = GuestSurvey::query()->lockForUpdate()->findOrFail($survey->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['survey' => ['Only draft surveys can be published.']]);
            }

            if (($locked->questions ?? []) === []) {
                throw ValidationException::withMessages(['questions' => ['Add at least one question before publishing.']]);
            }

            $locked->update([
                'status' => 'published',
                'publication_sequence' => ((int) GuestSurvey::query()->max('publication_sequence')) + 1,
                'published_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Survey published successfully.',
            'survey' => GuestSurveyResource::make($survey->refresh()->load('restaurant'))->resolve($request),
        ]);
    }

    /**
     * Send the survey immediately or schedule for later.
     *
     * Platform surveys go to all active users.
     * Restaurant surveys go to guests with reservations at that restaurant.
     */
    public function send(SendAdminSurveyRequest $request, GuestSurvey $survey): JsonResponse
    {
        if ($survey->status !== 'published') {
            throw ValidationException::withMessages(['survey' => ['Only published surveys can be sent.']]);
        }

        $validated = $request->validated();
        $scheduledAt = isset($validated['scheduled_at']) ? now()->parse($validated['scheduled_at']) : null;

        $dispatch = AdminSurveyDispatch::query()->create([
            'guest_survey_id' => $survey->id,
            'status' => 'pending',
            'scheduled_at' => $scheduledAt,
        ]);

        if ($scheduledAt !== null) {
            DispatchAdminSurveyJob::dispatch($survey->id, $dispatch->id)->delay($scheduledAt);
        } else {
            DispatchAdminSurveyJob::dispatch($survey->id, $dispatch->id);
        }

        return response()->json([
            'message' => $scheduledAt
                ? 'Survey scheduled for dispatch.'
                : 'Survey dispatch queued.',
            'dispatch' => [
                'id' => $dispatch->id,
                'status' => $dispatch->status,
                'scheduled_at' => $dispatch->scheduled_at?->toIso8601String(),
            ],
        ], 202);
    }

    public function destroy(Request $request, GuestSurvey $survey): JsonResponse
    {
        DB::transaction(function () use ($survey): void {
            $locked = GuestSurvey::query()->lockForUpdate()->findOrFail($survey->id);

            if ($locked->status !== 'draft' || $locked->invitations()->exists()) {
                throw ValidationException::withMessages(['survey' => ['Only unreferenced draft surveys can be deleted.']]);
            }

            $locked->delete();
        });

        return response()->json(['message' => 'Survey deleted successfully.']);
    }
}

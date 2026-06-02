<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMoretableLineupRequest;
use App\Http\Requests\Admin\UpdateMoretableLineupRequest;
use App\Http\Requests\Admin\UploadMoretableLineupCoverRequest;
use App\Http\Resources\MoretableLineupResource;
use App\Models\MoretableLineup;
use App\MoretableLineupStatus;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Admin Moretable Lineup', weight: 53)]
class AdminMoretableLineupController extends Controller
{
    public function __construct(protected MediaLibraryService $mediaLibraryService) {}

    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[QueryParameter('search', type: 'string', required: false)]
    #[QueryParameter('status', type: 'string', required: false, example: 'published')]
    #[Response(200, type: 'array{data: list<MoretableLineupResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $lineups = MoretableLineup::query()
            ->with(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media'])
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($lineupQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $lineupQuery
                        ->where('title', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                }),
            )
            ->when(
                filled($request->string('status')->toString()),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => MoretableLineupResource::collection($lineups->getCollection())->resolve($request),
            'links' => [
                'first' => $lineups->url(1),
                'last' => $lineups->url($lineups->lastPage()),
                'prev' => $lineups->previousPageUrl(),
                'next' => $lineups->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $lineups->currentPage(),
                'from' => $lineups->firstItem(),
                'last_page' => $lineups->lastPage(),
                'path' => $lineups->path(),
                'per_page' => $lineups->perPage(),
                'to' => $lineups->lastItem(),
                'total' => $lineups->total(),
            ],
        ]);
    }

    public function store(StoreMoretableLineupRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $validated['slug'] = $validated['slug'] ?? $this->uniqueSlugForTitle($validated['title']);

        if (($validated['status'] ?? null) === MoretableLineupStatus::Published->value && empty($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        $lineup = MoretableLineup::query()->create($validated);

        return response()->json([
            'message' => 'Moretable lineup created successfully.',
            'lineup' => MoretableLineupResource::make($lineup->load(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media'])),
        ], 201);
    }

    public function show(Request $request, MoretableLineup $moretableLineup): MoretableLineupResource
    {
        $this->ensureAdminAccess($request);

        return MoretableLineupResource::make(
            $moretableLineup->load(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media']),
        );
    }

    public function update(UpdateMoretableLineupRequest $request, MoretableLineup $moretableLineup): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();

        if (array_key_exists('title', $validated) && ! array_key_exists('slug', $validated)) {
            $validated['slug'] = $this->uniqueSlugForTitle($validated['title'], $moretableLineup->id);
        }

        if (
            ($validated['status'] ?? null) === MoretableLineupStatus::Published->value
            && $moretableLineup->published_at === null
            && empty($validated['published_at'])
        ) {
            $validated['published_at'] = now();
        }

        $moretableLineup->fill($validated)->save();

        return response()->json([
            'message' => 'Moretable lineup updated successfully.',
            'lineup' => MoretableLineupResource::make(
                $moretableLineup->refresh()->load(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media']),
            ),
        ]);
    }

    public function uploadCover(UploadMoretableLineupCoverRequest $request, MoretableLineup $moretableLineup): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $this->mediaLibraryService->addUploadedFileToCollection(
            $moretableLineup,
            $request->file('cover'),
            'cover',
            ['alt_text' => $request->validated('alt_text')],
        );

        return response()->json([
            'message' => 'Cover image uploaded successfully.',
            'lineup' => MoretableLineupResource::make(
                $moretableLineup->load(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media']),
            ),
        ], 201);
    }

    public function deleteCover(Request $request, MoretableLineup $moretableLineup): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $moretableLineup->clearMediaCollection('cover');

        return response()->json([
            'message' => 'Cover image removed successfully.',
        ]);
    }

    public function destroy(Request $request, MoretableLineup $moretableLineup): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $moretableLineup->delete();

        return response()->json([
            'message' => 'Moretable lineup deleted successfully.',
        ]);
    }

    protected function uniqueSlugForTitle(string $title, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($title);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'lineup';
        $candidate = $baseSlug;
        $suffix = 2;

        while (
            MoretableLineup::query()
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MoretableLineupResource;
use App\Models\MoretableLineup;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Public Moretable Lineup', weight: 1)]
class PublicMoretableLineupController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[Response(200, type: 'array{data: list<MoretableLineupResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $lineups = MoretableLineup::query()
            ->published()
            ->with(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media'])
            ->orderByDesc('is_featured')
            ->latest('published_at')
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

    public function show(Request $request, string $slug): MoretableLineupResource
    {
        $lineup = MoretableLineup::query()
            ->published()
            ->with(['media', 'restaurant', 'restaurant.cuisines', 'restaurant.media'])
            ->where('slug', $slug)
            ->firstOrFail();

        return MoretableLineupResource::make($lineup);
    }
}

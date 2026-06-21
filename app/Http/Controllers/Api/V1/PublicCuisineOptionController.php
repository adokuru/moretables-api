<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CuisineOptionResource;
use App\Models\CuisineOption;
use App\Services\PerformanceCacheService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Public Restaurants', weight: 1)]
class PublicCuisineOptionController extends Controller
{
    public function __construct(protected PerformanceCacheService $performanceCache) {}

    /**
     * List cuisine options for filtering and onboarding flows (read-only catalog).
     */
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 25)]
    #[QueryParameter('search', type: 'string', description: 'Filter by cuisine name')]
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);
        $search = $request->string('search')->trim()->value();

        if ($search !== '') {
            $options = CuisineOption::query()
                ->where('name', 'LIKE', "%{$search}%")
                ->orderBy('name')
                ->paginate($perPage);

            return response()->json(CuisineOptionResource::collection($options)->response()->getData(true));
        }

        $payload = $this->performanceCache->flexible(
            $this->performanceCache->versionedKey(
                'cuisine-options',
                (string) $request->integer('page', 1),
                (string) $perPage,
            ),
            'cuisine_options',
            function () use ($perPage): array {
                $options = CuisineOption::query()
                    ->orderBy('name')
                    ->paginate($perPage);

                return CuisineOptionResource::collection($options)->response()->getData(true);
            },
        );

        return response()->json($payload);
    }
}

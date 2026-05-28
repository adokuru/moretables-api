<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminCuisineRequest;
use App\Http\Requests\Admin\UpdateAdminCuisineRequest;
use App\Http\Resources\CuisineResource;
use App\Models\CuisineOption;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Group('Admin Cuisines', weight: 52)]
class AdminCuisineController extends Controller
{
    #[QueryParameter('page', type: 'integer', default: 1, example: 1)]
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 20)]
    #[QueryParameter('search', type: 'string', required: false)]
    #[Response(200, type: 'array{data: list<CuisineResource>, links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, from: int|null, last_page: int, path: string, per_page: int, to: int|null, total: int}}')]
    public function index(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $cuisines = CuisineOption::query()
            ->when(
                filled($request->string('search')->toString()),
                fn ($query) => $query->where(function ($cuisineQuery) use ($request): void {
                    $search = $request->string('search')->toString();

                    $cuisineQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                }),
            )
            ->orderBy('name')
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => CuisineResource::collection($cuisines->getCollection())->resolve($request),
            'links' => [
                'first' => $cuisines->url(1),
                'last' => $cuisines->url($cuisines->lastPage()),
                'prev' => $cuisines->previousPageUrl(),
                'next' => $cuisines->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $cuisines->currentPage(),
                'from' => $cuisines->firstItem(),
                'last_page' => $cuisines->lastPage(),
                'path' => $cuisines->path(),
                'per_page' => $cuisines->perPage(),
                'to' => $cuisines->lastItem(),
                'total' => $cuisines->total(),
            ],
        ]);
    }

    public function store(StoreAdminCuisineRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $name = $validated['name'];
        $slug = $validated['slug'] ?? $this->uniqueSlugForName($name);

        $cuisine = CuisineOption::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Cuisine created successfully.',
            'cuisine' => CuisineResource::make($cuisine),
        ], 201);
    }

    public function show(Request $request, CuisineOption $cuisineOption): CuisineResource
    {
        $this->ensureAdminAccess($request);

        return CuisineResource::make($cuisineOption);
    }

    public function update(UpdateAdminCuisineRequest $request, CuisineOption $cuisineOption): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $cuisineOption->name = $validated['name'];
        }

        if (array_key_exists('slug', $validated)) {
            $cuisineOption->slug = $validated['slug'];
        } elseif (array_key_exists('name', $validated)) {
            $cuisineOption->slug = $this->uniqueSlugForName($validated['name'], $cuisineOption->id);
        }

        $cuisineOption->save();

        return response()->json([
            'message' => 'Cuisine updated successfully.',
            'cuisine' => CuisineResource::make($cuisineOption->refresh()),
        ]);
    }

    public function destroy(Request $request, CuisineOption $cuisineOption): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $cuisineOption->delete();

        return response()->json([
            'message' => 'Cuisine deleted successfully.',
        ]);
    }

    protected function uniqueSlugForName(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'cuisine';
        $candidate = $baseSlug;
        $suffix = 2;

        while (
            CuisineOption::query()
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

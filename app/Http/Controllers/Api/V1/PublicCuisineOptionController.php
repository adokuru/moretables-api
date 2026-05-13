<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CuisineOptionResource;
use App\Models\CuisineOption;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Public Restaurants', weight: 1)]
class PublicCuisineOptionController extends Controller
{
    /**
     * List cuisine options for filtering and onboarding flows (read-only catalog).
     */
    #[QueryParameter('per_page', type: 'integer', default: 20, example: 25)]
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request);

        $options = CuisineOption::query()
            ->orderBy('name')
            ->paginate($perPage);

        return CuisineOptionResource::collection($options)->toResponse($request);
    }
}

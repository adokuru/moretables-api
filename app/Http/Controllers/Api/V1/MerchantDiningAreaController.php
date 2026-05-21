<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreDiningAreaRequest;
use App\Http\Requests\Merchant\SyncDiningAreaLayoutRequest;
use App\Http\Requests\Merchant\UpdateDiningAreaRequest;
use App\Http\Resources\DiningAreaResource;
use App\Models\DiningArea;
use App\Models\Restaurant;
use App\Services\AuditLogService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Floor Plan', weight: 32)]
class MerchantDiningAreaController extends Controller
{
    public function __construct(protected AuditLogService $auditLogService) {}

    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        return response()->json(DiningAreaResource::collection(
            $restaurant->diningAreas()->with('tables')->orderBy('sort_order')->get()
        ));
    }

    public function store(StoreDiningAreaRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $diningArea = $restaurant->diningAreas()->create($request->validated());

        $this->auditLogService->log(
            action: 'dining_area.created',
            actor: $request->user(),
            auditable: $diningArea,
            restaurant: $restaurant,
            organization: $restaurant->organization,
            description: 'Dining area created',
        );

        return response()->json([
            'message' => 'Dining area created successfully.',
            'dining_area' => DiningAreaResource::make($diningArea->load('tables')),
        ], 201);
    }

    public function update(UpdateDiningAreaRequest $request, Restaurant $restaurant, DiningArea $diningArea): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);

        $diningArea->update($request->validated());

        return response()->json([
            'message' => 'Dining area updated successfully.',
            'dining_area' => DiningAreaResource::make($diningArea->refresh()->load('tables')),
        ]);
    }

    public function destroy(Restaurant $restaurant, DiningArea $diningArea): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);

        $diningArea->delete();

        return response()->json([
            'message' => 'Dining area deleted successfully.',
        ]);
    }

    public function syncLayout(SyncDiningAreaLayoutRequest $request, Restaurant $restaurant, DiningArea $diningArea): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($diningArea->restaurant_id === $restaurant->id, 404);

        $rotationMap = ['r1' => 0, 'r2' => 90, 'r3' => 180, 'r4' => 270];

        $diningArea->tables()->delete();

        foreach ($request->validated()['tables'] as $index => $table) {
            $diningArea->tables()->create([
                'restaurant_id' => $restaurant->id,
                'name'          => $table['table_label'],
                'layout_type'   => $table['layout_type'],
                'x_position'    => $table['x_position'],
                'y_position'    => $table['y_position'],
                'color'         => $table['table_color'] ?? null,
                'chair_color'   => $table['chair_color'] ?? null,
                'rotation'      => $rotationMap[$table['rotate'] ?? 'r1'],
                'min_capacity'  => $table['min_party_size'] ?? null,
                'max_capacity'  => $table['max_party_size'] ?? 1,
                'sort_order'    => $index,
            ]);
        }

        return response()->json([
            'message'     => 'Layout saved successfully.',
            'dining_area' => DiningAreaResource::make($diningArea->refresh()->load('tables')),
        ]);
    }
}

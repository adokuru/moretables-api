<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreDiningAreaRequest;
use App\Http\Requests\Merchant\SyncDiningAreaLayoutRequest;
use App\Http\Requests\Merchant\UpdateDiningAreaRequest;
use App\Http\Resources\DiningAreaResource;
use App\Models\DiningArea;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
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

        $tableType = request()->query('table_type');
        $diningSpotId = request()->query('dining_spot_id');

        return response()->json(DiningAreaResource::collection(
            $restaurant->diningAreas()
                ->with(['tables' => fn ($q) => $q
                    ->when($tableType, fn ($q) => $q->where('table_type', $tableType))
                    ->when($diningSpotId, fn ($q) => $q->where('dining_spot_id', $diningSpotId)),
                ])
                ->orderBy('sort_order')
                ->get()
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

        $tables = $request->validated()['tables'];

        // Validate min <= max party size
        foreach ($tables as $table) {
            $min = $table['min_party_size'] ?? null;
            $max = $table['max_party_size'] ?? null;
            if ($min !== null && $max !== null && $min > $max) {
                abort(422, "Table \"{$table['table_label']}\": min party size ({$min}) cannot be greater than max party size ({$max}).");
            }
        }

        // Validate no overlapping table rectangles
        $placed = [];
        foreach ($tables as $table) {
            $x1 = $table['x_position'];
            $y1 = $table['y_position'];
            $x2 = $x1 + ($table['width'] ?? 1);
            $y2 = $y1 + ($table['height'] ?? 1);

            foreach ($placed as [$rx1, $ry1, $rx2, $ry2, $label]) {
                if ($x1 < $rx2 && $x2 > $rx1 && $y1 < $ry2 && $y2 > $ry1) {
                    abort(422, "Table \"{$table['table_label']}\" overlaps with table \"{$label}\" at position ({$x1},{$y1}).");
                }
            }

            $placed[] = [$x1, $y1, $x2, $y2, $table['table_label']];
        }

        $existingTables = $diningArea->tables()->get()->keyBy('id');
        $incomingIds = collect($tables)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

        $deletedTableIds = $existingTables
            ->reject(fn (RestaurantTable $table) => in_array($table->id, $incomingIds, true))
            ->pluck('id');

        if ($incomingIds === []) {
            $diningArea->tables()->delete();
        } else {
            $diningArea->tables()->whereNotIn('id', $incomingIds)->delete();
        }

        foreach ($tables as $index => $table) {
            $existingTable = isset($table['id']) ? $existingTables->get((int) $table['id']) : null;

            $tableData = [
                'restaurant_id' => $restaurant->id,
                'name' => $table['table_label'],
                'layout_type' => $table['layout_type'],
                'x_position' => $table['x_position'],
                'y_position' => $table['y_position'],
                'width' => $table['width'] ?? 1,
                'height' => $table['height'] ?? 1,
                'color' => $table['table_color'] ?? null,
                'chair_color' => $table['chair_color'] ?? null,
                'rotation' => $table['rotation'] ?? 0,
                'dining_spot_id' => $table['dining_spot_id'] ?? null,
                'min_capacity' => $table['min_party_size'] ?? RestaurantTable::DEFAULT_MIN_CAPACITY,
                'max_capacity' => $table['max_party_size'] ?? RestaurantTable::DEFAULT_MAX_CAPACITY,
                'sort_order' => $index,
                'table_type' => $existingTable?->table_type ?? 'regular',
            ];

            if ($existingTable) {
                $existingTable->update($tableData);
            } else {
                $diningArea->tables()->create($tableData);
            }
        }

        if ($deletedTableIds->isNotEmpty()) {
            $deletedTableIdLookup = $deletedTableIds->mapWithKeys(fn ($id) => [(int) $id => true])->all();

            $restaurant->tableCombinations()
                ->where('dining_area_id', $diningArea->id)
                ->select(['id', 'table_ids'])
                ->chunkById(500, function ($combinations) use ($deletedTableIdLookup): void {
                    foreach ($combinations as $combination) {
                        foreach ($combination->table_ids as $tableId) {
                            if (isset($deletedTableIdLookup[(int) $tableId])) {
                                $combination->delete();
                                break;
                            }
                        }
                    }
                });
        }

        return response()->json([
            'message' => 'Layout saved successfully.',
            'dining_area' => DiningAreaResource::make($diningArea->refresh()->load('tables')),
        ]);
    }
}

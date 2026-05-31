<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StoreTableCombinationRequest;
use App\Http\Requests\Merchant\UpdateTableCombinationRequest;
use App\Http\Resources\TableCombinationResource;
use App\Models\Restaurant;
use App\Models\TableCombination;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Table Combinations', weight: 33)]
class MerchantTableCombinationController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $validTableIdLookup = $restaurant->tables()
            ->when(
                $request->filled('dining_area_id'),
                fn ($q) => $q->where('dining_area_id', $request->integer('dining_area_id'))
            )
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip()
            ->all();

        $combinations = $restaurant->tableCombinations()
            ->when(
                $request->filled('dining_area_id'),
                fn ($q) => $q->where('dining_area_id', $request->integer('dining_area_id'))
            )
            ->orderBy('created_at')
            ->get()
            ->filter(function (TableCombination $combination) use ($validTableIdLookup): bool {
                foreach ($combination->table_ids as $tableId) {
                    if (! isset($validTableIdLookup[(int) $tableId])) {
                        return false;
                    }
                }

                return true;
            })
            ->values();

        return response()->json(['data' => TableCombinationResource::collection($combinations)]);
    }

    public function store(StoreTableCombinationRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $data = $request->validated();

        // Sort table_ids so [5,6] and [6,5] are treated as the same combination.
        $data['table_ids'] = TableCombination::normalizeTableIds($data['table_ids']);

        $existing = $restaurant->tableCombinations()
            ->where('table_ids', json_encode($data['table_ids']))
            ->where('dining_area_id', $data['dining_area_id'] ?? null)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'This combination already exists.',
                'data' => TableCombinationResource::make($existing),
            ]);
        }

        $combination = $restaurant->tableCombinations()->create($data);

        return response()->json([
            'message' => 'Combination saved.',
            'data' => TableCombinationResource::make($combination),
        ], 201);
    }

    public function update(UpdateTableCombinationRequest $request, Restaurant $restaurant, TableCombination $combination): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($combination->restaurant_id === $restaurant->id, 404);

        $combination->update($request->validated());

        return response()->json([
            'message' => 'Combination updated.',
            'data' => TableCombinationResource::make($combination->fresh()),
        ]);
    }

    public function destroy(Request $request, Restaurant $restaurant, TableCombination $combination): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($combination->restaurant_id === $restaurant->id, 404);

        $combination->delete();

        return response()->json(['message' => 'Combination deleted.']);
    }
}

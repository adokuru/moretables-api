<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\TableStatusUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\AssignTableServerRequest;
use App\Http\Requests\Merchant\StoreRestaurantTableRequest;
use App\Http\Requests\Merchant\UpdateRestaurantTableRequest;
use App\Http\Requests\Merchant\UpdateTableStatusRequest;
use App\Http\Resources\RestaurantServerResource;
use App\Http\Resources\RestaurantTableResource;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Services\AuditLogService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Floor Plan', weight: 32)]
class MerchantTableController extends Controller
{
    public function __construct(protected AuditLogService $auditLogService) {}

    public function index(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        return response()->json(RestaurantTableResource::collection(
            $restaurant->tables()->orderBy('sort_order')->get()
        ));
    }

    public function store(StoreRestaurantTableRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $validated = $request->validated();
        $validated['restaurant_id'] = $restaurant->id;

        $table = $restaurant->tables()->create($validated);

        return response()->json([
            'message' => 'Table created successfully.',
            'table' => RestaurantTableResource::make($table),
        ], 201);
    }

    public function update(UpdateRestaurantTableRequest $request, Restaurant $restaurant, RestaurantTable $table): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($table->restaurant_id === $restaurant->id, 404);

        $table->update($request->validated());

        return response()->json([
            'message' => 'Table updated successfully.',
            'table' => RestaurantTableResource::make($table->refresh()),
        ]);
    }

    public function updateStatus(UpdateTableStatusRequest $request, Restaurant $restaurant, RestaurantTable $table): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($table->restaurant_id === $restaurant->id, 404);

        $table->update(['status' => $request->validated('status')]);
        event(new TableStatusUpdated($table->refresh(), 'status_changed'));

        return response()->json([
            'message' => 'Table status updated successfully.',
            'table' => RestaurantTableResource::make($table),
        ]);
    }

    public function assignServer(AssignTableServerRequest $request, Restaurant $restaurant, RestaurantTable $table): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($table->restaurant_id === $restaurant->id, 404);

        $table->update(['assigned_server_id' => $request->validated('server_id')]);

        return response()->json([
            'message' => $request->validated('server_id')
                ? 'Server assigned successfully.'
                : 'Server unassigned successfully.',
            'table' => RestaurantTableResource::make($table->refresh()),
        ]);
    }

    // Powers the guest-detail panel's "Assigned servers" section: if a
    // `table_id` is given and that table already has a server, that's the
    // only one returned; otherwise (no table yet, or the table is free) the
    // pool of servers with no table assigned anywhere — both computed as
    // real DB queries, not by shipping the whole roster to the client.
    // `table_id` is optional so guests without a table yet (waitlist/
    // reservation, not in seat mode) can still see who's free to staff them.
    public function availableServers(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $request->validate(['table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id']]);

        $tableId = $request->integer('table_id') ?: null;
        if ($tableId) {
            $table = RestaurantTable::where('restaurant_id', $restaurant->id)->findOrFail($tableId);
            if ($table->assigned_server_id) {
                return response()->json([
                    'assigned_server' => RestaurantServerResource::make($table->assignedServer),
                    'servers' => [],
                ]);
            }
        }

        $unassigned = $restaurant->servers()
            ->whereDoesntHave('assignedTables')
            ->orderBy('name')
            ->get();

        return response()->json([
            'assigned_server' => null,
            'servers' => RestaurantServerResource::collection($unassigned),
        ]);
    }

    public function destroy(Restaurant $restaurant, RestaurantTable $table): JsonResponse
    {
        abort_unless(request()->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);
        abort_unless($table->restaurant_id === $restaurant->id, 404);

        $table->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }
}

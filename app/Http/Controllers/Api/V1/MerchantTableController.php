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
use App\Models\RestaurantServerTableAssignment;
use App\Models\RestaurantTable;
use App\Services\AuditLogService;
use Carbon\Carbon;
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

        $validated = $request->validated();
        $serviceStartsAt = Carbon::parse($validated['service_starts_at'])->utc();
        $serviceEndsAt = Carbon::parse($validated['service_ends_at'])->utc();
        $assignment = RestaurantServerTableAssignment::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('restaurant_table_id', $table->id)
            ->where('service_starts_at', $serviceStartsAt)
            ->first();

        if ($validated['server_id'] === null) {
            $assignment?->delete();
            $assignment = null;
        } else {
            $assignment = RestaurantServerTableAssignment::query()->updateOrCreate(
                [
                    'restaurant_table_id' => $table->id,
                    'service_starts_at' => $serviceStartsAt,
                ],
                [
                    'restaurant_id' => $restaurant->id,
                    'restaurant_server_id' => $validated['server_id'],
                    'service_ends_at' => $serviceEndsAt,
                ],
            );
        }

        // The legacy column represented a permanent assignment. Keep it empty;
        // current assignments are resolved from the selected service period.
        if ($table->assigned_server_id !== null) {
            $table->update(['assigned_server_id' => null]);
        }
        $table->refresh()->setAttribute('assigned_server_id', $assignment?->restaurant_server_id);

        return response()->json([
            'message' => $validated['server_id']
                ? 'Server assigned successfully.'
                : 'Server unassigned successfully.',
            'table' => RestaurantTableResource::make($table),
            'assignment' => $assignment ? [
                'server_id' => $assignment->restaurant_server_id,
                'table_id' => $assignment->restaurant_table_id,
                'service_starts_at' => $assignment->service_starts_at?->toIso8601String(),
                'service_ends_at' => $assignment->service_ends_at?->toIso8601String(),
            ] : null,
        ]);
    }

    // Powers the guest-detail panel's "Assigned servers" section for one
    // dated service period. Servers may cover multiple tables in a section,
    // so an unassigned table receives the full shift roster.
    public function availableServers(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('tables.manage', $restaurant), 403);

        $validated = $request->validate([
            'table_id' => ['nullable', 'integer', 'exists:restaurant_tables,id'],
            'service_starts_at' => ['required', 'date'],
            'service_ends_at' => ['required', 'date', 'after:service_starts_at'],
        ]);
        $serviceStartsAt = Carbon::parse($validated['service_starts_at'])->utc();
        $serviceEndsAt = Carbon::parse($validated['service_ends_at'])->utc();

        $tableId = isset($validated['table_id']) ? (int) $validated['table_id'] : null;
        if ($tableId) {
            $table = RestaurantTable::where('restaurant_id', $restaurant->id)->findOrFail($tableId);
            $assignment = RestaurantServerTableAssignment::query()
                ->with('server')
                ->where('restaurant_id', $restaurant->id)
                ->where('restaurant_table_id', $table->id)
                ->where('service_starts_at', $serviceStartsAt)
                ->where('service_ends_at', $serviceEndsAt)
                ->first();
            if ($assignment) {
                return response()->json([
                    'assigned_server' => RestaurantServerResource::make($assignment->server),
                    'servers' => [],
                ]);
            }
        }

        $servers = $restaurant->servers()
            ->orderBy('name')
            ->get();

        return response()->json([
            'assigned_server' => null,
            'servers' => RestaurantServerResource::collection($servers),
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

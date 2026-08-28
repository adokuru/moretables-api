<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Front of House / Timelines', weight: 37)]
class FrontOfHouseTimelineController extends Controller
{
    public function activeFloors(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $timezone = $restaurant->timezone ?? 'UTC';
        $date = $request->filled('date')
            ? $request->string('date')->toString()
            : now($timezone)->toDateString();

        $dayStart = Carbon::parse($date, $timezone)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $timezone)->endOfDay()->utc();

        $activeAreaIds = $restaurant->reservations()
            ->with(['assignedTables:id,dining_area_id', 'table:id,dining_area_id'])
            ->whereBetween('reservations.starts_at', [$dayStart, $dayEnd])
            ->whereNotIn('reservations.status', ['cancelled', 'no_show'])
            ->get()
            ->flatMap(fn ($reservation) => $reservation->assignedTables->pluck('dining_area_id')->whenEmpty(
                fn ($areas) => $reservation->table?->dining_area_id ? $areas->push($reservation->table->dining_area_id) : $areas,
            ))
            ->filter()
            ->unique();

        $floors = $restaurant->diningAreas()
            ->whereIn('id', $activeAreaIds)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return response()->json(['data' => $floors]);
    }

    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'dining_area_id' => ['nullable', 'integer', 'exists:dining_areas,id'],
        ]);

        $timezone = $restaurant->timezone ?? 'UTC';
        $date = $request->filled('date')
            ? $request->string('date')->toString()
            : now($timezone)->toDateString();

        $diningAreaId = $request->integer('dining_area_id') ?: null;

        $diningAreasQuery = $restaurant->diningAreas()
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')]);

        if ($diningAreaId) {
            $diningAreasQuery->where('id', $diningAreaId);
        }

        $tables = $diningAreasQuery->get()->flatMap(fn ($area) => $area->tables);
        $tableIds = $tables->pluck('id');

        $dayStart = Carbon::parse($date, $timezone)->startOfDay()->utc();
        $dayEnd = Carbon::parse($date, $timezone)->endOfDay()->utc();

        $reservations = $restaurant->reservations()
            ->with(['assignedTables:id', 'guestContact'])
            ->whereBetween('starts_at', [$dayStart, $dayEnd])
            ->where(function ($query) use ($tableIds) {
                $query->whereIn('restaurant_table_id', $tableIds)
                    ->orWhereHas('assignedTables', fn ($tables) => $tables->whereIn('restaurant_tables.id', $tableIds));
            })
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->orderBy('starts_at')
            ->get();

        $reservationsByTable = collect();
        foreach ($reservations as $reservation) {
            $assignedTableIds = $reservation->assignedTables->pluck('id');
            if ($assignedTableIds->isEmpty() && $reservation->restaurant_table_id !== null) {
                $assignedTableIds = collect([$reservation->restaurant_table_id]);
            }

            foreach ($assignedTableIds as $tableId) {
                $reservationsByTable->put(
                    (int) $tableId,
                    $reservationsByTable->get((int) $tableId, collect())->push($reservation),
                );
            }
        }

        $data = $tables->map(function ($table) use ($reservationsByTable, $timezone) {
            $reservations = ($reservationsByTable[$table->id] ?? collect())->map(function ($res) use ($timezone) {
                $endsAt = $res->ends_at ?? $res->starts_at?->copy()->addMinutes(60);

                $contact = $res->guestContact;
                $name = $contact
                    ? trim("{$contact->first_name} {$contact->last_name}")
                    : 'Guest';

                return [
                    'id' => $res->id,
                    'name' => $name,
                    'party_size' => $res->party_size,
                    'starts_at' => $res->starts_at?->setTimezone($timezone)->toIso8601String(),
                    'ends_at' => $endsAt?->setTimezone($timezone)->toIso8601String(),
                    'status' => $res->status?->value,
                    'service_stage' => $res->service_stage?->value,
                ];
            })->values();

            return [
                'id' => $table->id,
                'table_label' => $table->name,
                'space' => $table->max_capacity,
                'table_color' => $table->color,
                'chair_color' => $table->chair_color,
                'reservations' => $reservations,
            ];
        })->values();

        return response()->json([
            'date' => $date,
            'data' => $data,
        ]);
    }
}

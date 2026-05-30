<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\RestaurantDateRangeService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Front of House / Timelines', weight: 37)]
class FrontOfHouseTimelineController extends Controller
{
    public function __construct(private readonly RestaurantDateRangeService $dateRanges) {}

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
        $dateRange = $this->dateRanges->forDate($restaurant, $date);

        $reservationsByTable = $restaurant->reservations()
            ->with('guestContact')
            ->where('starts_at', '>=', $dateRange['start'])
            ->where('starts_at', '<', $dateRange['end'])
            ->whereIn('restaurant_table_id', $tableIds)
            ->whereNotIn('status', ['canceled', 'no_show'])
            ->orderBy('starts_at')
            ->get()
            ->groupBy('restaurant_table_id');

        $data = $tables->map(function ($table) use ($reservationsByTable, $timezone) {
            $reservations = ($reservationsByTable[$table->id] ?? collect())->map(function ($res) use ($timezone) {
                $endsAt = $res->ends_at ?? $res->starts_at?->copy()->addMinutes(90);

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

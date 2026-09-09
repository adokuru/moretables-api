<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\Reporting\ReportingFilterService;
use App\Services\Reporting\ReportingQueryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Merchant / Reporting', weight: 36)]
class MerchantReportingController extends Controller
{
    public function __construct(
        private readonly ReportingFilterService $filters,
        private readonly ReportingQueryService $reporting,
    ) {}

    /** Reporting-authorized shift options include IDs, weekdays and start/end times. */
    public function filters(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);

        return response()->json($this->reporting->filtersMetadata($restaurant));
    }

    /** Calendar series include empty buckets; summary trend is null when the comparison total is zero. */
    public function shiftOccupancy(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);
        $context = $this->filters->resolveContext($request, $restaurant);

        return response()->json($this->reporting->shiftOccupancy($restaurant, $context));
    }

    /** Calendar series include empty buckets; summary trend is null when the comparison total is zero. */
    public function coverTrends(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);
        $context = $this->filters->resolveContext($request, $restaurant);

        return response()->json($this->reporting->coverTrends($restaurant, $context));
    }

    /** Calendar series include empty buckets; summary trend is null when the comparison total is zero. */
    public function firstTimeVisits(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);
        $context = $this->filters->resolveContext($request, $restaurant);

        return response()->json($this->reporting->firstTimeVisits($restaurant, $context));
    }

    public function guestFrequency(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);
        $context = $this->filters->resolveContext($request, $restaurant, [
            'include_frequency_period' => true,
        ]);

        return response()->json($this->reporting->guestFrequency($restaurant, $context));
    }

    public function exportGuestFrequency(Request $request, Restaurant $restaurant): StreamedResponse
    {
        $this->authorizeExport($request, $restaurant);
        $request->merge(['export' => true]);
        $context = $this->filters->resolveContext($request, $restaurant, [
            'include_frequency_period' => true,
        ]);

        $payload = $this->reporting->guestFrequency($restaurant, $context);

        return $this->csvResponse(
            'guest-frequency.csv',
            $this->reporting->guestRowsToCsv(collect($payload['data'])),
        );
    }

    /** Reservation parties and covers; summary trends are null when their comparison totals are zero. */
    public function reservations(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);
        $context = $this->filters->resolveContext($request, $restaurant, [
            'include_status' => true,
        ]);

        return response()->json($this->reporting->reservations($restaurant, $context));
    }

    public function exportReservations(Request $request, Restaurant $restaurant): StreamedResponse
    {
        $this->authorizeExport($request, $restaurant);
        $request->merge(['export' => true, 'page' => 1]);
        $context = $this->filters->resolveContext($request, $restaurant, [
            'include_status' => true,
        ]);

        $payload = $this->reporting->reservations($restaurant, $context);

        return $this->csvResponse(
            'reservations.csv',
            $this->reporting->reservationRowsToCsv(collect($payload['data'])),
        );
    }

    /** Measured turn times compared with sampled visits' shift settings; missing averages display —. */
    public function turnTimes(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);
        $context = $this->filters->resolveContext($request, $restaurant);

        return response()->json($this->reporting->turnTimes($restaurant, $context));
    }

    /** Guest visits filtered by frequency_period (all_time or last_month), or explicit inclusive dates. */
    public function guestExport(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->authorizeReporting($request, $restaurant);
        $context = $this->filters->resolveContext($request, $restaurant, ['include_frequency_period' => true]);

        return response()->json($this->reporting->guestExport($restaurant, $context));
    }

    /** Complete CSV for the same guest period; pagination does not limit the export. */
    public function exportGuestExport(Request $request, Restaurant $restaurant): StreamedResponse
    {
        $this->authorizeExport($request, $restaurant);
        $request->merge(['export' => true]);
        $context = $this->filters->resolveContext($request, $restaurant, ['include_frequency_period' => true]);

        $payload = $this->reporting->guestExport($restaurant, $context);

        return $this->csvResponse(
            'guest-export.csv',
            $this->reporting->guestRowsToCsv(collect($payload['data'])),
        );
    }

    private function authorizeReporting(Request $request, Restaurant $restaurant): void
    {
        abort_unless($request->user()->hasAnyRestaurantPermission(['reservations.view', 'audit_logs.view'], $restaurant), 403);
    }

    /**
     * Exporting is a distinct capability from viewing (Reporting Exporting Data vs
     * Reporting View) — deliberately does not fall back to reservations.view/
     * audit_logs.view, only restaurants.manage as the usual super-permission.
     */
    private function authorizeExport(Request $request, Restaurant $restaurant): void
    {
        abort_unless($request->user()->hasAnyRestaurantPermission(['reporting.export', 'restaurants.manage'], $restaurant), 403);
    }

    private function csvResponse(string $filename, string $content): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($content): void {
                echo $content;
            },
            $filename,
            [
                'Content-Type' => 'text/csv',
            ],
        );
    }
}

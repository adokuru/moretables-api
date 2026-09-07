<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\GroupReportingRequest;
use App\Models\Organization;
use App\Services\Reporting\GroupReportingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Merchant / Reporting', weight: 36)]
class MerchantGroupReportingController extends Controller
{
    public function __construct(private readonly GroupReportingService $reporting) {}

    /** Business-wide ratings and seated covers. Requires an active Premium business with multiple restaurants and organization-scoped reporting permission. Spend is unavailable (null). */
    public function show(GroupReportingRequest $request, Organization $organization): JsonResponse
    {
        return response()->json($this->reporting->report($organization, $request->validated()));
    }

    /** Export all matching group rows, with the same comparisons as the report. Requires organization-scoped export permission. */
    public function export(GroupReportingRequest $request, Organization $organization): StreamedResponse
    {
        $payload = $this->reporting->report($organization, $request->validated());
        $csv = $this->reporting->csv($payload['data']);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'group-reporting.csv', ['Content-Type' => 'text/csv']);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Reference', weight: 1)]
class StatusIconController extends Controller
{
    /**
     * Front-of-house status icon catalog.
     *
     * Returns the label, colour and icon key for every reservation, service
     * stage, finished, cancel/no-show and waitlist status. The apps render
     * floor tables, timeline blocks and status tiles from this catalog.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status_icons' => config('status_icons'),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\BillingPlanSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateGuestCommunicationSettingRequest;
use App\Http\Resources\GuestCommunicationSettingResource;
use App\Models\GuestCommunicationSetting;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Guest Communication', weight: 38)]
class MerchantGuestCommunicationController extends Controller
{
    private const UPGRADE_MESSAGE = 'Upgrade to Premium to send automated email campaigns to guests.';

    public function show(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasAnyRestaurantPermission(
            ['restaurants.view', 'communications.manage', 'messaging.manage'],
            $restaurant,
        ), 403);

        $setting = $this->settingFor($restaurant);

        // Automated Email Campaigns (both tabs) are Premium-only (docs/PLAN_PERMISSIONS.md).
        // Report as off — without persisting anything — for a restaurant that can no longer
        // (or never could) use it, e.g. after a downgrade from Premium.
        if (! $restaurant->hasPlanAtLeast(BillingPlanSlug::Premium)) {
            $setting->automated_messaging_enabled = false;
            $setting->reservation_messaging_enabled = false;
        }

        return response()->json([
            'guest_communication' => GuestCommunicationSettingResource::make($setting),
        ]);
    }

    public function updateAutomatedMessaging(
        UpdateGuestCommunicationSettingRequest $request,
        Restaurant $restaurant,
    ): JsonResponse {
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.manage', 'messaging.manage'], $restaurant), 403);
        $this->abortUnlessMayEnable($request, $restaurant);

        $setting = $this->settingFor($restaurant);
        $setting->update([
            'automated_messaging_enabled' => $request->boolean('enabled'),
        ]);

        return response()->json([
            'message' => 'Automated messaging settings updated successfully.',
            'guest_communication' => GuestCommunicationSettingResource::make($setting->refresh()),
        ]);
    }

    public function updateReservationMessaging(
        UpdateGuestCommunicationSettingRequest $request,
        Restaurant $restaurant,
    ): JsonResponse {
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.manage', 'communications.manage'], $restaurant), 403);
        $this->abortUnlessMayEnable($request, $restaurant);

        $setting = $this->settingFor($restaurant);
        $setting->update([
            'reservation_messaging_enabled' => $request->boolean('enabled'),
        ]);

        return response()->json([
            'message' => 'Reservation messaging settings updated successfully.',
            'guest_communication' => GuestCommunicationSettingResource::make($setting->refresh()),
        ]);
    }

    /**
     * Turning the toggle off is always allowed; turning it on requires Premium.
     */
    private function abortUnlessMayEnable(UpdateGuestCommunicationSettingRequest $request, Restaurant $restaurant): void
    {
        abort_unless(
            ! $request->boolean('enabled') || $restaurant->hasPlanAtLeast(BillingPlanSlug::Premium),
            403,
            self::UPGRADE_MESSAGE,
        );
    }

    protected function settingFor(Restaurant $restaurant): GuestCommunicationSetting
    {
        return $restaurant->guestCommunicationSetting()->firstOrCreate([])->refresh();
    }
}

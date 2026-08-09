<?php

namespace App\Http\Controllers\Api\V1;

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
    public function show(Restaurant $restaurant): JsonResponse
    {
        abort_unless(request()->user()->hasAnyRestaurantPermission(
            ['restaurants.view', 'communications.manage', 'messaging.manage'],
            $restaurant,
        ), 403);

        return response()->json([
            'guest_communication' => GuestCommunicationSettingResource::make($this->settingFor($restaurant)),
        ]);
    }

    public function updateAutomatedMessaging(
        UpdateGuestCommunicationSettingRequest $request,
        Restaurant $restaurant,
    ): JsonResponse {
        abort_unless($request->user()->hasAnyRestaurantPermission(['restaurants.manage', 'messaging.manage'], $restaurant), 403);

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

        $setting = $this->settingFor($restaurant);
        $setting->update([
            'reservation_messaging_enabled' => $request->boolean('enabled'),
        ]);

        return response()->json([
            'message' => 'Reservation messaging settings updated successfully.',
            'guest_communication' => GuestCommunicationSettingResource::make($setting->refresh()),
        ]);
    }

    protected function settingFor(Restaurant $restaurant): GuestCommunicationSetting
    {
        return $restaurant->guestCommunicationSetting()->firstOrCreate([])->refresh();
    }
}

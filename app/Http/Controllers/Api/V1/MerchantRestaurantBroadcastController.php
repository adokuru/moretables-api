<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\SendRestaurantBroadcastRequest;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\RestaurantBroadcastNotification;
use App\ReservationStatus;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Notification;

#[Group('Merchant Broadcasts', weight: 38)]
class MerchantRestaurantBroadcastController extends Controller
{
    /**
     * Send a broadcast (in-app + push notification) to the restaurant's guests.
     *
     * Recipients are users that have at least one active or completed reservation
     * with the restaurant. Audience is either every such user, or only those linked
     * to the provided guest contact ids.
     */
    public function store(SendRestaurantBroadcastRequest $request, Restaurant $restaurant): JsonResponse
    {
        $validated = $request->validated();

        // Broadcast Messaging tab (audience "all") is gated by messaging.manage;
        // Guest Email tab (audience "selected") is gated by communications.manage.
        // reservations.manage remains a valid fallback for both — this endpoint
        // was reservations.manage-only before Communications Channels/Direct
        // Messaging existed as real permissions.
        $requiredPermission = $validated['audience'] === 'all' ? 'messaging.manage' : 'communications.manage';
        abort_unless($request->user()->hasAnyRestaurantPermission(['reservations.manage', $requiredPermission], $restaurant), 403);

        $recipients = $this->recipientsQuery(
            $restaurant,
            $validated['audience'] === 'selected' ? $validated['guest_contact_ids'] : null,
        );

        $recipientsCount = (clone $recipients)->count();

        $notification = new RestaurantBroadcastNotification(
            $restaurant,
            $validated['title'],
            $validated['message'],
        );

        $recipients->chunkById(500, function ($users) use ($notification): void {
            Notification::send($users, $notification);
        });

        return response()->json([
            'message' => 'Broadcast queued.',
            'recipients_count' => $recipientsCount,
        ], 202);
    }

    /**
     * Users with an active or completed reservation at the restaurant, optionally
     * narrowed to reservations tied to the given guest contact ids.
     *
     * @param  array<int, int>|null  $guestContactIds
     * @return Builder<User>
     */
    protected function recipientsQuery(Restaurant $restaurant, ?array $guestContactIds): Builder
    {
        return User::query()->whereHas('reservations', function (Builder $query) use ($restaurant, $guestContactIds): void {
            $query->where('restaurant_id', $restaurant->id)
                ->whereNotIn('status', [ReservationStatus::Cancelled, ReservationStatus::NoShow]);

            if ($guestContactIds !== null) {
                $query->whereIn('guest_contact_id', $guestContactIds);
            }
        });
    }
}

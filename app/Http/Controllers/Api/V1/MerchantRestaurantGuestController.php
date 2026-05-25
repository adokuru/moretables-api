<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestContactResource;
use App\Models\Restaurant;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant Guests', weight: 37)]
class MerchantRestaurantGuestController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $guests = $restaurant->guestContacts()
            ->where('is_temporary', false)
            ->when($request->filled('search_term'), function ($query) use ($request): void {
                $term = $request->string('search_term')->toString();
                $query->where(function ($q) use ($term): void {
                    $q->where('first_name', 'LIKE', "%{$term}%")
                        ->orWhere('last_name', 'LIKE', "%{$term}%")
                        ->orWhere('email', 'LIKE', "%{$term}%")
                        ->orWhere('phone', 'LIKE', "%{$term}%");
                });
            })
            ->paginate(20);

        return response()->json(GuestContactResource::collection($guests));
    }
}

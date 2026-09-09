<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\GuestContactResource;
use App\Models\Restaurant;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Merchant Guests', weight: 37)]
class MerchantRestaurantGuestController extends Controller
{
    /**
     * Search restaurant guests. Set contact_only=true to search email and phone only.
     * Local Nigerian and international phone formats are matched equivalently.
     */
    public function index(Request $request, Restaurant $restaurant): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasRestaurantPermission('reservations.view', $restaurant), 403);

        $guests = $restaurant->guestContacts()
            ->where('is_temporary', false)
            ->when($request->filled('search_term'), function ($query) use ($request): void {
                $term = trim($request->string('search_term')->toString());
                $phone = preg_match('/^[+()\d\s-]+$/', $term) ? PhoneNumber::forWhatsApp($term) : '';
                $query->where(function ($q) use ($term, $phone, $request): void {
                    $q->where('email', 'LIKE', "%{$term}%");
                    if (! $request->boolean('contact_only')) {
                        $q->orWhere('first_name', 'LIKE', "%{$term}%")
                            ->orWhere('last_name', 'LIKE', "%{$term}%");
                    }
                    if ($phone !== '') {
                        $variants = [$phone];
                        if (str_starts_with($phone, '234') && strlen($phone) === 13) {
                            $variants[] = '0'.substr($phone, 3);
                        }
                        foreach ($variants as $variant) {
                            $q->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ["%{$variant}%"]);
                        }
                    }
                });
            })
            ->paginate(20);

        // Returning the resource collection directly (rather than manually
        // wrapping it in response()->json()) lets Laravel's routing layer
        // apply its standard paginated-resource response — {data, links,
        // meta} — automatically. The previous response()->json(...) call
        // bypassed that pipeline entirely and serialized a bare array with
        // no `data`/`meta` keys at all, which every frontend caller of this
        // endpoint (GuestContactListResponse) expects to exist.
        return GuestContactResource::collection($guests);
    }
}

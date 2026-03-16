<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\UpdateRestaurantRequest;
use App\Http\Resources\RestaurantDetailResource;
use App\Models\Restaurant;
use App\Services\AuditLogService;
use App\Services\MediaLibraryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant Restaurant Profile', weight: 30)]
class MerchantRestaurantController extends Controller
{
    public function __construct(
        protected AuditLogService $auditLogService,
        protected MediaLibraryService $mediaLibraryService,
    ) {}

    public function show(Restaurant $restaurant): RestaurantDetailResource
    {
        abort_unless(request()->user()->canManageRestaurant($restaurant), 403);

        return RestaurantDetailResource::make($restaurant->load([
            'cuisines',
            'media',
            'hours',
            'policy',
            'menuItems.media',
            'diningAreas.tables',
        ]));
    }

    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->canManageRestaurant($restaurant), 403);

        $validated = $request->validated();
        $oldValues = $restaurant->toArray();

        $restaurant->fill(collect($validated)->except([
            'cuisines',
            'hours',
            'policy',
            'featured_image',
            'featured_image_alt_text',
            'gallery_images',
            'gallery_image_alt_texts',
        ])->toArray());
        $restaurant->save();

        if (array_key_exists('cuisines', $validated)) {
            $restaurant->cuisines()->delete();
            foreach ($validated['cuisines'] as $cuisine) {
                $restaurant->cuisines()->create(['name' => $cuisine]);
            }
        }

        if (array_key_exists('hours', $validated)) {
            foreach ($validated['hours'] as $hour) {
                $restaurant->hours()->updateOrCreate(
                    ['day_of_week' => $hour['day_of_week']],
                    [
                        'opens_at' => $hour['opens_at'] ?? null,
                        'closes_at' => $hour['closes_at'] ?? null,
                        'is_closed' => $hour['is_closed'] ?? false,
                    ],
                );
            }
        }

        if (array_key_exists('policy', $validated)) {
            $restaurant->policy()->updateOrCreate(['restaurant_id' => $restaurant->id], $validated['policy']);
        }

        $this->mediaLibraryService->syncUploadedMedia($restaurant, $validated);

        $restaurant->load(['cuisines', 'media', 'hours', 'policy', 'menuItems.media', 'diningAreas.tables']);

        $this->auditLogService->log(
            action: 'restaurant.updated',
            actor: $request->user(),
            auditable: $restaurant,
            oldValues: $oldValues,
            newValues: $restaurant->toArray(),
            restaurant: $restaurant,
            organization: $restaurant->organization,
            description: 'Restaurant profile updated',
        );

        return response()->json([
            'message' => 'Restaurant updated successfully.',
            'restaurant' => RestaurantDetailResource::make($restaurant),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RestaurantAccessConfigResource;
use App\Models\Restaurant;
use App\Models\RestaurantAccessConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Manage per-restaurant access configurations (roles).
 *
 * Each restaurant starts with 5 default access configs (Principal Admin,
 * Operations, Analytics & Reporting, Marketing & Growth, Guest Relations).
 * Staff members are assigned one access config which determines their permissions.
 */
#[Group('Merchant Access Config', weight: 30)]
class MerchantAccessConfigController extends Controller
{
    /**
     * List access configs.
     *
     * Returns all access configurations for the restaurant, including the 5
     * system defaults and any custom configs created by the merchant.
     */
    public function index(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);

        $configs = $restaurant->accessConfigs()
            ->orderByRaw("CASE slug
                WHEN 'principal_admin'     THEN 0
                WHEN 'operations'          THEN 1
                WHEN 'analytics_reporting' THEN 2
                WHEN 'marketing_growth'    THEN 3
                WHEN 'guest_relations'     THEN 4
                ELSE 5
            END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'access_configs' => RestaurantAccessConfigResource::collection($configs),
        ]);
    }

    /**
     * Create an access config.
     *
     * Creates a custom access configuration with a chosen set of permissions.
     */
    public function store(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);

        $availablePermissions = [
            // system permissions (used for backend auth checks)
            'restaurants.view',
            'restaurants.manage',
            'reservations.view',
            'reservations.manage',
            'waitlist.manage',
            'tables.manage',
            'staff.manage',
            'audit_logs.view',
            // ui permissions (matching Figma access config labels)
            'reporting.export',
            'integrations.manage',
            'marketing.manage',
            'billing.manage',
            'communications.manage',
            'messaging.manage',
            'policies.manage',
        ];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in($availablePermissions)],
        ]);

        $slug = Str::slug($validated['name'], '_');

        abort_if(
            $restaurant->accessConfigs()->where('slug', $slug)->exists(),
            422,
            'An access config with this name already exists for this restaurant.'
        );

        $config = $restaurant->accessConfigs()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'permissions' => array_unique($validated['permissions']),
            'is_default' => false,
        ]);

        return response()->json([
            'message' => 'Access config created successfully.',
            'access_config' => RestaurantAccessConfigResource::make($config),
        ], 201);
    }

    /**
     * Get an access config.
     */
    public function show(Request $request, Restaurant $restaurant, RestaurantAccessConfig $accessConfig): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);
        abort_unless($accessConfig->restaurant_id === $restaurant->id, 404);

        return response()->json([
            'access_config' => RestaurantAccessConfigResource::make($accessConfig),
        ]);
    }

    /**
     * Update an access config.
     *
     * Updates the name, description, and/or permissions of an access config.
     * The permissions of default configs can be adjusted to suit the restaurant's needs.
     */
    public function update(Request $request, Restaurant $restaurant, RestaurantAccessConfig $accessConfig): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);
        abort_unless($accessConfig->restaurant_id === $restaurant->id, 404);

        $availablePermissions = [
            'restaurants.view',
            'restaurants.manage',
            'reservations.view',
            'reservations.manage',
            'waitlist.manage',
            'tables.manage',
            'staff.manage',
            'audit_logs.view',
            'reporting.export',
            'integrations.manage',
            'marketing.manage',
            'billing.manage',
            'communications.manage',
            'messaging.manage',
            'policies.manage',
        ];

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in($availablePermissions)],
        ]);

        if (isset($validated['permissions'])) {
            $validated['permissions'] = array_unique($validated['permissions']);
        }

        $accessConfig->update($validated);

        return response()->json([
            'message' => 'Access config updated successfully.',
            'access_config' => RestaurantAccessConfigResource::make($accessConfig->fresh()),
        ]);
    }

    /**
     * Delete an access config.
     *
     * Default access configs can be deleted if no staff are currently assigned to them.
     * Custom configs can always be deleted (staff assignments will lose their config reference).
     */
    public function destroy(Request $request, Restaurant $restaurant, RestaurantAccessConfig $accessConfig): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);
        abort_unless($accessConfig->restaurant_id === $restaurant->id, 404);

        abort_if(
            $accessConfig->userRoles()->exists(),
            422,
            'Cannot delete an access config that has staff members assigned to it.'
        );

        $accessConfig->delete();

        return response()->json([
            'message' => 'Access config deleted successfully.',
        ]);
    }

    /**
     * List available permissions.
     *
     * Returns all permission slugs that can be assigned to an access config.
     */
    public function permissions(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('staff.manage', $restaurant), 403);

        return response()->json([
            'permissions' => [
                ['name' => 'restaurants.view', 'description' => 'View restaurant profile and settings'],
                ['name' => 'restaurants.manage', 'description' => 'Edit restaurant profile and settings'],
                ['name' => 'reservations.view', 'description' => 'View reservations and guest details'],
                ['name' => 'reservations.manage', 'description' => 'Create, update, and cancel reservations'],
                ['name' => 'waitlist.manage', 'description' => 'Manage the waitlist'],
                ['name' => 'tables.manage', 'description' => 'Manage tables and floor plans'],
                ['name' => 'staff.manage', 'description' => 'Invite and manage staff members'],
                ['name' => 'audit_logs.view', 'description' => 'View audit logs and reports'],
            ],
        ]);
    }
}

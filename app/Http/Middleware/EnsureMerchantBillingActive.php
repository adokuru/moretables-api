<?php

namespace App\Http\Middleware;

use App\Models\BillingPlan;
use App\Models\Restaurant;
use App\Services\BillingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantBillingActive
{
    public function __construct(protected BillingService $billingService) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $restaurant = $request->route('restaurant');

        if (! $restaurant instanceof Restaurant) {
            $restaurant = Restaurant::query()->find($restaurant);
        }

        if (! $restaurant || ! $this->hasActiveBillingPlans()) {
            return $next($request);
        }

        if ($request->user()?->canAccessRestaurant($restaurant) === false) {
            return $next($request);
        }

        if ($this->billingService->isRestaurantBillable($restaurant)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Payment required. Please activate billing to continue using merchant features.',
            'billing' => [
                'status' => 'unpaid',
                'is_active' => false,
                'payment_url' => config('billing.frontend_billing_url'),
                'plans_url' => url('/api/v1/merchant/billing/plans'),
                'checkout_url' => url('/api/v1/merchant/restaurants/'.$restaurant->id.'/billing/checkout'),
            ],
        ], 402);
    }

    protected function hasActiveBillingPlans(): bool
    {
        return BillingPlan::query()
            ->where('is_active', true)
            ->exists();
    }
}

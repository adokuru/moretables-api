<?php

namespace App\Http\Middleware;

use App\Models\BillingPlan;
use App\Services\BillingService;
use App\Services\PerformanceCacheService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantBillingActive
{
    public function __construct(
        protected BillingService $billingService,
        protected PerformanceCacheService $performanceCache,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/merchant/restaurants/*/onboarding*')) {
            return $next($request);
        }

        $restaurant = $request->route('restaurant');

        if ($restaurant && $this->hasActiveBillingPlans() && ! $this->billingService->isRestaurantBillable($restaurant)) {
            // The status the merchant app routes on: a business whose subscription lapsed should
            // land on the renewal screen, not the never-subscribed one, even though the restaurant
            // itself never held a subscription of its own.
            $lapsedSubscription = $restaurant->latestBillingSubscription
                ?? $restaurant->organization?->latestBillingSubscription;

            return response()->json([
                'message' => 'An active billing subscription is required for this restaurant.',
                'billing' => [
                    'status' => $lapsedSubscription?->status?->value ?? 'unpaid',
                ],
            ], 402);
        }

        return $next($request);
    }

    protected function hasActiveBillingPlans(): bool
    {
        return Cache::remember(
            $this->performanceCache->versionedKey('billing-plans'),
            (int) config('performance.cache.ttls.billing_eligibility'),
            fn (): bool => BillingPlan::query()
                ->where('is_active', true)
                ->exists(),
        );
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\BillingPlan;
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
        if ($request->is('api/v1/merchant/restaurants/*/onboarding*')) {
            return $next($request);
        }

        $restaurant = $request->route('restaurant');

        if ($restaurant && $this->hasActiveBillingPlans() && ! $this->billingService->isRestaurantBillable($restaurant)) {
            return response()->json([
                'message' => 'An active billing subscription is required for this restaurant.',
                'billing' => [
                    'status' => 'unpaid',
                ],
            ], 402);
        }

        return $next($request);
    }

    protected function hasActiveBillingPlans(): bool
    {
        return BillingPlan::query()
            ->where('is_active', true)
            ->exists();
    }
}

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
        // TODO: enable per-plan feature gating once subscription tiers are defined.
        // Each plan slug (foundation / core / premium) will unlock different features.
        return $next($request);
    }

    protected function hasActiveBillingPlans(): bool
    {
        return BillingPlan::query()
            ->where('is_active', true)
            ->exists();
    }
}

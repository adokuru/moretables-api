<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminBillingInvoiceIndexRequest;
use App\Http\Requests\Admin\AdminBillingPaymentIndexRequest;
use App\Http\Requests\Admin\AdminBillingSubscriptionIndexRequest;
use App\Http\Requests\Admin\StoreAdminBillingSubscriptionRequest;
use App\Http\Resources\BillingPlanResource;
use App\Http\Resources\MerchantBillingResource;
use App\Http\Resources\MerchantInvoiceResource;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\MerchantPayment;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Services\BillingService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Admin Billing', weight: 57)]
class AdminBillingController extends Controller
{
    public function __construct(protected BillingService $billingService) {}

    public function plans(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $plans = BillingPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'plans' => BillingPlanResource::collection($plans),
        ]);
    }

    public function restaurantPlan(Request $request, Restaurant $restaurant): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $restaurant->loadMissing([
            'organization.activeBillingSubscription.plan',
            'activeBillingSubscription.plan',
            'latestBillingSubscription.plan',
            'defaultPaymentMethod',
        ]);

        $subscription = $restaurant->effectiveBillingSubscription() ?? $restaurant->latestBillingSubscription;

        return response()->json([
            'organization' => [
                'id' => $restaurant->organization?->id,
                'name' => $restaurant->organization?->name,
                'slug' => $restaurant->organization?->slug,
                'billing_email' => $restaurant->organization?->billing_email,
            ],
            'restaurant' => [
                'id' => $restaurant->id,
                'name' => $restaurant->name,
                'slug' => $restaurant->slug,
            ],
            'billing' => MerchantBillingResource::make($this->restaurantBillingPayload($restaurant, $subscription)),
            'billing_scope' => $subscription?->isBusinessLevel() ? 'business' : 'restaurant',
            'plan' => $subscription?->plan
                ? BillingPlanResource::make($subscription->plan)
                : null,
            'subscription' => $subscription
                ? $this->subscriptionPayload($subscription)
                : null,
        ]);
    }

    public function businessPlan(Request $request, Organization $organization): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $organization->loadMissing(['activeBillingSubscription.plan', 'latestBillingSubscription.plan']);

        $subscription = $organization->activeBillingSubscription ?? $organization->latestBillingSubscription;

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'billing_email' => $organization->billing_email,
                'restaurants_count' => $organization->restaurants()->count(),
            ],
            'billing' => MerchantBillingResource::make([
                'is_active' => $this->billingService->isBusinessBillable($organization),
                'scope' => 'business',
                'business' => $organization,
                'subscription' => $subscription?->loadMissing('plan'),
                'payment_method' => $organization->defaultPaymentMethod()->first(),
                'upcoming_invoice' => $organization->invoices()->with('plan')->where('status', 'pending')->latest()->first(),
            ]),
            'plan' => $subscription?->plan
                ? BillingPlanResource::make($subscription->plan)
                : null,
            'subscription' => $subscription
                ? $this->subscriptionPayload($subscription)
                : null,
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $totalRevenue = MerchantPayment::query()
            ->where('status', 'success')
            ->sum('amount');

        $activeSubscriptions = MerchantSubscription::query()
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->count();

        $overdueInvoices = MerchantInvoice::query()
            ->where('status', 'overdue')
            ->count();

        $payingRestaurants = MerchantSubscription::query()
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->distinct('restaurant_id')
            ->count('restaurant_id');

        $payingBusinesses = MerchantSubscription::query()
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->distinct('organization_id')
            ->count('organization_id');

        return response()->json([
            'overview' => [
                'total_revenue' => $totalRevenue,
                'currency' => 'NGN',
                'active_subscriptions' => $activeSubscriptions,
                'overdue_invoices' => $overdueInvoices,
                'paying_restaurants' => $payingRestaurants,
                'paying_businesses' => $payingBusinesses,
            ],
        ]);
    }

    public function subscriptions(AdminBillingSubscriptionIndexRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $subscriptions = MerchantSubscription::query()
            ->with(['plan', 'organization', 'restaurant.organization'])
            ->when(
                filled($request->string('search')->toString()),
                function ($query) use ($request): void {
                    $search = $request->string('search')->toString();

                    $query->where(function ($scopeQuery) use ($search): void {
                        $scopeQuery
                            ->whereHas('restaurant', function ($restaurantQuery) use ($search): void {
                                $restaurantQuery
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('slug', 'like', '%'.$search.'%')
                                    ->orWhereHas('organization', function ($organizationQuery) use ($search): void {
                                        $organizationQuery
                                            ->where('name', 'like', '%'.$search.'%')
                                            ->orWhere('slug', 'like', '%'.$search.'%');
                                    });
                            })
                            ->orWhereHas('organization', function ($organizationQuery) use ($search): void {
                                $organizationQuery
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('slug', 'like', '%'.$search.'%');
                            });
                    });
                },
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('restaurant_id'),
                fn ($query) => $query->where('restaurant_id', $request->integer('restaurant_id')),
            )
            ->when(
                $request->filled('organization_id'),
                fn ($query) => $query->where('organization_id', $request->integer('organization_id')),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => $subscriptions->getCollection()
                ->map(fn (MerchantSubscription $subscription): array => $this->subscriptionPayload($subscription))
                ->values(),
            'links' => [
                'first' => $subscriptions->url(1),
                'last' => $subscriptions->url($subscriptions->lastPage()),
                'prev' => $subscriptions->previousPageUrl(),
                'next' => $subscriptions->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'from' => $subscriptions->firstItem(),
                'last_page' => $subscriptions->lastPage(),
                'path' => $subscriptions->path(),
                'per_page' => $subscriptions->perPage(),
                'to' => $subscriptions->lastItem(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    public function storeSubscription(StoreAdminBillingSubscriptionRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $validated = $request->validated();
        $restaurant = isset($validated['restaurant_id'])
            ? Restaurant::query()->findOrFail($validated['restaurant_id'])
            : null;
        $organization = $restaurant
            ? $restaurant->organization
            : Organization::query()->findOrFail($validated['organization_id']);
        $owner = $restaurant ?? $organization;
        $plan = BillingPlan::query()
            ->where('slug', $validated['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        if ($owner instanceof Organization && ! $this->billingService->planCoversBusiness($organization, $plan)) {
            return response()->json([
                'message' => "The {$plan->name} plan covers {$plan->max_restaurants} restaurant(s), but this business has {$organization->restaurants()->count()}.",
            ], 422);
        }

        [$result] = DB::transaction(function () use ($validated, $owner, $organization, $restaurant, $plan, $request): array {
            $result = $this->billingService->assignAdminSubscription(
                owner: $owner,
                plan: $plan,
                admin: $request->user(),
                attributes: [
                    'status' => $validated['status'] ?? null,
                    'current_period_end' => $validated['current_period_end'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'record_invoice' => $validated['record_invoice'] ?? true,
                ],
            );

            $this->logAdminAudit(
                $request,
                'billing.subscription.assigned',
                $result['subscription'],
                newValues: [
                    'organization_id' => $organization?->id,
                    'restaurant_id' => $restaurant?->id,
                    'scope' => $result['subscription']->isBusinessLevel() ? 'business' : 'restaurant',
                    'plan' => $plan->slug?->value ?? $validated['plan'],
                    'status' => $result['subscription']->status?->value,
                ],
                description: $validated['notes'] ?? null,
                restaurant: $restaurant,
                organization: $organization,
            );

            return [$result];
        });

        return response()->json([
            'message' => 'Subscription assigned successfully.',
            'subscription' => $this->subscriptionPayload($result['subscription']),
            'invoice' => $result['invoice']
                ? MerchantInvoiceResource::make($result['invoice'])
                : null,
        ], 201);
    }

    public function invoices(AdminBillingInvoiceIndexRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $invoices = MerchantInvoice::query()
            ->with(['plan', 'organization', 'restaurant.organization', 'payments'])
            ->when(
                filled($request->string('search')->toString()),
                function ($query) use ($request): void {
                    $search = $request->string('search')->toString();

                    $query->where(function ($invoiceQuery) use ($search): void {
                        $invoiceQuery
                            ->where('invoice_number', 'like', '%'.$search.'%')
                            ->orWhere('provider_reference', 'like', '%'.$search.'%')
                            ->orWhereHas('restaurant', function ($restaurantQuery) use ($search): void {
                                $restaurantQuery
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->orWhereHas('organization', fn ($organizationQuery) => $organizationQuery->where('name', 'like', '%'.$search.'%'));
                            });
                    });
                },
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('restaurant_id'),
                fn ($query) => $query->where('restaurant_id', $request->integer('restaurant_id')),
            )
            ->when(
                $request->filled('organization_id'),
                fn ($query) => $query->where('organization_id', $request->integer('organization_id')),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => MerchantInvoiceResource::collection($invoices->getCollection())->resolve($request),
            'links' => [
                'first' => $invoices->url(1),
                'last' => $invoices->url($invoices->lastPage()),
                'prev' => $invoices->previousPageUrl(),
                'next' => $invoices->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'from' => $invoices->firstItem(),
                'last_page' => $invoices->lastPage(),
                'path' => $invoices->path(),
                'per_page' => $invoices->perPage(),
                'to' => $invoices->lastItem(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    public function payments(AdminBillingPaymentIndexRequest $request): JsonResponse
    {
        $this->ensureAdminAccess($request);

        $payments = MerchantPayment::query()
            ->with(['invoice', 'paymentMethod', 'organization', 'restaurant.organization'])
            ->when(
                filled($request->string('search')->toString()),
                function ($query) use ($request): void {
                    $search = $request->string('search')->toString();

                    $query->where(function ($paymentQuery) use ($search): void {
                        $paymentQuery
                            ->where('reference', 'like', '%'.$search.'%')
                            ->orWhere('gateway_response', 'like', '%'.$search.'%')
                            ->orWhereHas('restaurant', function ($restaurantQuery) use ($search): void {
                                $restaurantQuery
                                    ->where('name', 'like', '%'.$search.'%')
                                    ->orWhereHas('organization', fn ($organizationQuery) => $organizationQuery->where('name', 'like', '%'.$search.'%'));
                            });
                    });
                },
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')->toString()),
            )
            ->when(
                $request->filled('restaurant_id'),
                fn ($query) => $query->where('restaurant_id', $request->integer('restaurant_id')),
            )
            ->when(
                $request->filled('organization_id'),
                fn ($query) => $query->where('organization_id', $request->integer('organization_id')),
            )
            ->latest()
            ->paginate($this->perPage($request))
            ->appends($request->query());

        return response()->json([
            'data' => $payments->getCollection()->map(function (MerchantPayment $payment): array {
                return [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'provider' => $payment->provider?->value,
                    'status' => $payment->status?->value,
                    'amount' => $payment->amount,
                    'display_amount' => number_format($payment->amount / 100, 2),
                    'currency' => $payment->currency,
                    'channel' => $payment->channel,
                    'gateway_response' => $payment->gateway_response,
                    'paid_at' => optional($payment->paid_at)?->toIso8601String(),
                    'invoice' => $payment->invoice ? [
                        'id' => $payment->invoice->id,
                        'invoice_number' => $payment->invoice->invoice_number,
                    ] : null,
                    'restaurant' => [
                        'id' => $payment->restaurant?->id,
                        'name' => $payment->restaurant?->name,
                        'slug' => $payment->restaurant?->slug,
                    ],
                    'organization' => [
                        'id' => $payment->organization?->id ?? $payment->restaurant?->organization?->id,
                        'name' => $payment->organization?->name ?? $payment->restaurant?->organization?->name,
                        'slug' => $payment->organization?->slug ?? $payment->restaurant?->organization?->slug,
                        'billing_email' => $payment->organization?->billing_email ?? $payment->restaurant?->organization?->billing_email,
                    ],
                    'payment_method' => $payment->paymentMethod ? [
                        'brand' => $payment->paymentMethod->brand,
                        'last4' => $payment->paymentMethod->last4,
                        'bank' => $payment->paymentMethod->bank,
                        'channel' => $payment->paymentMethod->channel,
                    ] : null,
                    'created_at' => optional($payment->created_at)?->toIso8601String(),
                ];
            })->values(),
            'links' => [
                'first' => $payments->url(1),
                'last' => $payments->url($payments->lastPage()),
                'prev' => $payments->previousPageUrl(),
                'next' => $payments->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $payments->currentPage(),
                'from' => $payments->firstItem(),
                'last_page' => $payments->lastPage(),
                'path' => $payments->path(),
                'per_page' => $payments->perPage(),
                'to' => $payments->lastItem(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionPayload(MerchantSubscription $subscription): array
    {
        $organization = $subscription->organization ?? $subscription->restaurant?->organization;

        return [
            'id' => $subscription->id,
            'scope' => $subscription->isBusinessLevel() ? 'business' : 'restaurant',
            'provider' => $subscription->provider?->value,
            'status' => $subscription->status?->value,
            'provider_subscription_code' => $subscription->provider_subscription_code,
            'current_period_start' => optional($subscription->current_period_start)?->toIso8601String(),
            'current_period_end' => optional($subscription->current_period_end)?->toIso8601String(),
            'next_payment_at' => optional($subscription->next_payment_at)?->toIso8601String(),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'metadata' => $subscription->metadata,
            'restaurant' => [
                'id' => $subscription->restaurant?->id,
                'name' => $subscription->restaurant?->name,
                'slug' => $subscription->restaurant?->slug,
            ],
            'organization' => [
                'id' => $organization?->id,
                'name' => $organization?->name,
                'slug' => $organization?->slug,
                'billing_email' => $organization?->billing_email,
            ],
            'plan' => [
                'id' => $subscription->plan?->id,
                'name' => $subscription->plan?->name,
                'slug' => $subscription->plan?->slug?->value,
                'amount' => $subscription->plan?->amount,
                'currency' => $subscription->plan?->currency,
                'interval' => $subscription->plan?->interval,
            ],
            'created_at' => optional($subscription->created_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array{is_active: bool, scope: string, business: ?Organization, subscription: ?MerchantSubscription, payment_method: ?MerchantPaymentMethod, upcoming_invoice: ?MerchantInvoice}
     */
    protected function restaurantBillingPayload(Restaurant $restaurant, ?MerchantSubscription $subscription): array
    {
        return [
            'is_active' => $this->billingService->isRestaurantBillable($restaurant),
            'scope' => $subscription?->isBusinessLevel() ? 'business' : 'restaurant',
            'business' => $restaurant->organization,
            'subscription' => $subscription?->loadMissing('plan'),
            'payment_method' => $restaurant->defaultPaymentMethod ?? $restaurant->organization?->defaultPaymentMethod()->first(),
            'upcoming_invoice' => $restaurant->invoices()
                ->with('plan')
                ->where('status', 'pending')
                ->latest()
                ->first(),
        ];
    }
}

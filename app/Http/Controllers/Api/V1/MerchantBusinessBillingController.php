<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StartBillingCheckoutRequest;
use App\Http\Resources\MerchantBillingResource;
use App\Http\Resources\MerchantInvoiceResource;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Services\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing for the business as a whole. One subscription here covers every restaurant the business
 * owns, up to the plan's restaurant allowance — the per-restaurant billing endpoints stay in place
 * for restaurants that bought their own subscription before billing moved to the business.
 */
#[Group('Merchant Business Billing', weight: 40)]
class MerchantBusinessBillingController extends Controller
{
    public function __construct(protected BillingService $billingService) {}

    public function show(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeBusiness($request, $organization, ['restaurants.view', 'billing.manage']);

        return response()->json([
            'billing' => MerchantBillingResource::make($this->billingPayload($organization)),
        ]);
    }

    public function checkout(StartBillingCheckoutRequest $request, Organization $organization): JsonResponse
    {
        $this->authorizeBusiness($request, $organization, ['restaurants.manage', 'billing.manage']);

        if ($this->billingService->isBusinessBillable($organization)) {
            return response()->json([
                'message' => 'This business already has an active subscription.',
            ], 422);
        }

        return $this->startCheckout($request, $organization, isUpgrade: false);
    }

    public function upgrade(StartBillingCheckoutRequest $request, Organization $organization): JsonResponse
    {
        $this->authorizeBusiness($request, $organization, ['restaurants.manage', 'billing.manage']);

        return $this->startCheckout($request, $organization, isUpgrade: true);
    }

    public function verify(Request $request, Organization $organization, string $reference): JsonResponse
    {
        $this->authorizeBusiness($request, $organization, ['restaurants.manage', 'billing.manage']);

        $verification = $this->billingService->verifyCheckout($organization, $reference);

        return response()->json([
            'message' => 'Billing checkout verified successfully.',
            'invoice' => MerchantInvoiceResource::make($verification['invoice']),
            'billing' => MerchantBillingResource::make($this->billingPayload($organization->refresh())),
        ]);
    }

    public function invoices(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeBusiness($request, $organization, ['restaurants.view', 'billing.manage']);

        $invoices = $organization->invoices()
            ->with(['plan', 'organization', 'payments'])
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'invoices' => [
                'data' => MerchantInvoiceResource::collection($invoices->items()),
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ],
        ]);
    }

    public function downloadInvoice(Request $request, Organization $organization, MerchantInvoice $invoice): Response
    {
        $this->authorizeBusiness($request, $organization, ['restaurants.view', 'billing.manage']);
        abort_unless((int) $invoice->organization_id === (int) $organization->id, 404);

        $invoice->loadMissing(['organization', 'restaurant.organization', 'plan', 'payments.paymentMethod']);

        $pdf = Pdf::loadView('pdf.merchant-invoice', [
            'invoice' => $invoice,
            'restaurant' => $invoice->restaurant,
            'organization' => $invoice->organization ?? $organization,
        ]);

        return $pdf->download($invoice->invoice_number.'.pdf');
    }

    protected function startCheckout(StartBillingCheckoutRequest $request, Organization $organization, bool $isUpgrade): JsonResponse
    {
        $plan = BillingPlan::query()
            ->where('slug', $request->validated('plan'))
            ->where('is_active', true)
            ->firstOrFail();

        $restaurantsCount = $organization->restaurants()->count();

        if (! $this->billingService->planCoversBusiness($organization, $plan)) {
            return response()->json([
                'message' => "The {$plan->name} plan covers {$plan->max_restaurants} restaurant(s), but this business has {$restaurantsCount}. Choose a plan that covers them all.",
                'restaurants_count' => $restaurantsCount,
                'restaurants_allowed' => $plan->max_restaurants,
            ], 422);
        }

        $checkout = $this->billingService->initializeCheckout($organization, $plan, isUpgrade: $isUpgrade, requesterEmail: $request->user()->email);

        return response()->json([
            'message' => $isUpgrade
                ? 'Billing upgrade initialized successfully.'
                : 'Billing checkout initialized successfully.',
            'checkout' => [
                'reference' => $checkout['reference'],
                'email' => $organization->billingEmail() ?? $request->user()->email,
                'plan_code' => $plan->provider_plan_code,
                'authorization_url' => data_get($checkout, 'provider_response.data.authorization_url'),
                'access_code' => data_get($checkout, 'provider_response.data.access_code'),
            ],
            'invoice' => MerchantInvoiceResource::make($checkout['invoice']->load('plan')),
        ], 201);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    protected function authorizeBusiness(Request $request, Organization $organization, array $permissionNames): void
    {
        foreach ($permissionNames as $permissionName) {
            if ($request->user()->hasPermission($permissionName, organization: $organization)) {
                return;
            }
        }

        abort(403);
    }

    /**
     * @return array{is_active: bool, scope: string, business: Organization, restaurants_count: int, subscription: ?MerchantSubscription, payment_method: ?MerchantPaymentMethod, upcoming_invoice: ?MerchantInvoice}
     */
    protected function billingPayload(Organization $organization): array
    {
        $subscription = $organization->billingSubscriptions()
            ->with('plan')
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        return [
            'is_active' => $this->billingService->isBusinessBillable($organization),
            'scope' => 'business',
            'business' => $organization,
            'restaurants_count' => $organization->restaurants()->count(),
            'subscription' => $subscription,
            'payment_method' => $organization->defaultPaymentMethod()->first(),
            'upcoming_invoice' => $organization->invoices()->with(['plan', 'organization'])->where('status', 'pending')->latest()->first(),
        ];
    }
}

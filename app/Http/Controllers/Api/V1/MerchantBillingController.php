<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchant\StartBillingCheckoutRequest;
use App\Http\Resources\BillingPlanResource;
use App\Http\Resources\MerchantBillingResource;
use App\Http\Resources\MerchantInvoiceResource;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantSubscription;
use App\Models\Restaurant;
use App\Services\BillingService;
use Barryvdh\DomPDF\Facade\Pdf;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Group('Merchant Billing', weight: 39)]
class MerchantBillingController extends Controller
{
    public function __construct(protected BillingService $billingService) {}

    public function plans(): JsonResponse
    {
        $plans = BillingPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'plans' => BillingPlanResource::collection($plans),
        ]);
    }

    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        return response()->json([
            'billing' => MerchantBillingResource::make($this->billingPayload($restaurant)),
        ]);
    }

    public function checkout(StartBillingCheckoutRequest $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $plan = BillingPlan::query()
            ->where('slug', $request->validated('plan'))
            ->where('is_active', true)
            ->firstOrFail();

        $checkout = $this->billingService->initializeCheckout($restaurant, $plan);

        return response()->json([
            'message' => 'Billing checkout initialized successfully.',
            'checkout' => [
                'authorization_url' => $checkout['authorization_url'],
                'access_code' => $checkout['access_code'],
                'reference' => $checkout['reference'],
            ],
            'invoice' => MerchantInvoiceResource::make($checkout['invoice']->load('plan')),
        ], 201);
    }

    public function verify(Request $request, Restaurant $restaurant, string $reference): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.manage', $restaurant), 403);

        $verification = $this->billingService->verifyCheckout($restaurant, $reference);

        return response()->json([
            'message' => 'Billing checkout verified successfully.',
            'invoice' => MerchantInvoiceResource::make($verification['invoice']),
            'billing' => MerchantBillingResource::make($this->billingPayload($restaurant->refresh())),
        ]);
    }

    public function invoices(Request $request, Restaurant $restaurant): JsonResponse
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);

        $invoices = $restaurant->invoices()
            ->with('plan')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'invoices' => MerchantInvoiceResource::collection($invoices),
        ]);
    }

    public function downloadInvoice(Request $request, Restaurant $restaurant, MerchantInvoice $invoice): Response
    {
        abort_unless($request->user()->hasRestaurantPermission('restaurants.view', $restaurant), 403);
        abort_unless((int) $invoice->restaurant_id === (int) $restaurant->id, 404);

        $invoice->loadMissing(['restaurant.organization', 'plan', 'payments.paymentMethod']);

        $pdf = Pdf::loadView('pdf.merchant-invoice', [
            'invoice' => $invoice,
            'restaurant' => $restaurant->loadMissing('organization'),
        ]);

        return $pdf->download($invoice->invoice_number.'.pdf');
    }

    /**
     * @return array{is_active: bool, subscription: ?MerchantSubscription, payment_method: ?MerchantPaymentMethod, upcoming_invoice: ?MerchantInvoice}
     */
    protected function billingPayload(Restaurant $restaurant): array
    {
        $subscription = $restaurant->billingSubscriptions()
            ->with('plan')
            ->whereIn('status', ['active', 'trialing', 'past_due'])
            ->latest()
            ->first();

        return [
            'is_active' => $this->billingService->isRestaurantBillable($restaurant),
            'subscription' => $subscription,
            'payment_method' => $restaurant->paymentMethods()->where('is_default', true)->latest()->first(),
            'upcoming_invoice' => $restaurant->invoices()->with('plan')->where('status', 'pending')->latest()->first(),
        ];
    }
}

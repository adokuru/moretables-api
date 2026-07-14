<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Services\ReservationCardHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function __construct(
        protected BillingService $billingService,
        protected ReservationCardHoldService $cardHoldService,
        protected PaymentProvider $paymentProvider,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');

        if (! $this->paymentProvider->validateWebhookSignature($payload, $signature)) {
            return response()->json([
                'message' => 'Invalid Paystack signature.',
            ], 400);
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return response()->json([
                'message' => 'Invalid Paystack payload.',
            ], 400);
        }

        $this->cardHoldService->handleWebhook($decoded);
        $this->billingService->handlePaystackWebhook($decoded);

        return response()->json([
            'message' => 'Webhook processed successfully.',
        ]);
    }
}

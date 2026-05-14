<?php

use App\MerchantInvoiceStatus;
use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\MerchantSubscription;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BillingPlanSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    config([
        'billing.providers.paystack.secret_key' => 'test-secret',
        'billing.providers.paystack.webhook_secret' => 'test-secret',
        'billing.providers.paystack.callback_url' => 'https://restaurant.moretables.test/billing/callback',
    ]);

    $this->seed(RoleAndPermissionSeeder::class);
    $this->seed(BillingPlanSeeder::class);
});

it('lists merchant billing plans', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/merchant/billing/plans')
        ->assertOk()
        ->assertJsonPath('plans.0.slug', 'foundation')
        ->assertJsonPath('plans.0.amount', 8500000)
        ->assertJsonPath('plans.1.slug', 'core')
        ->assertJsonPath('plans.2.slug', 'premium');
});

it('initializes paystack checkout for a restaurant plan', function (): void {
    $data = createBookableRestaurant();
    $manager = actingRestaurantManager($data);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'access-code',
            ],
        ]),
    ]);

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/checkout', [
        'plan' => 'core',
    ])->assertCreated()
        ->assertJsonPath('checkout.authorization_url', 'https://checkout.paystack.com/test')
        ->assertJsonPath('invoice.status', 'pending')
        ->assertJsonPath('invoice.plan.slug', 'core');

    expect(MerchantInvoice::query()
        ->where('restaurant_id', $data['restaurant']->id)
        ->where('amount', 13500000)
        ->exists())->toBeTrue();

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return ($payload['plan'] ?? null) === BillingPlan::query()->where('slug', 'core')->value('provider_plan_code')
            || ($payload['amount'] ?? null) === 13500000;
    });
    expect($manager)->toBeInstanceOf(User::class);
});

it('verifies checkout and persists paystack card summary', function (): void {
    $data = createBookableRestaurant();
    actingRestaurantManager($data);

    $plan = BillingPlan::query()->where('slug', 'foundation')->firstOrFail();
    $invoice = MerchantInvoice::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'provider_reference' => 'mt_verify_card',
        'amount' => $plan->amount,
        'status' => MerchantInvoiceStatus::Pending,
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/mt_verify_card' => Http::response([
            'status' => true,
            'data' => successfulPaystackTransaction('mt_verify_card', $plan->amount),
        ]),
    ]);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/verify/mt_verify_card')
        ->assertOk()
        ->assertJsonPath('invoice.status', 'paid')
        ->assertJsonPath('billing.is_active', true)
        ->assertJsonPath('billing.payment_method.last4', '4081')
        ->assertJsonPath('billing.payment_method.card_type', 'visa');

    $this->assertDatabaseHas('merchant_payment_methods', [
        'restaurant_id' => $data['restaurant']->id,
        'authorization_code' => 'AUTH_card',
        'last4' => '4081',
        'brand' => 'visa',
        'card_type' => 'visa',
    ]);

    $this->assertDatabaseHas('merchant_subscriptions', [
        'restaurant_id' => $data['restaurant']->id,
        'provider_subscription_code' => 'SUB_card',
        'status' => MerchantSubscriptionStatus::Active->value,
    ]);

    expect($invoice->refresh()->status)->toBe(MerchantInvoiceStatus::Paid);
});

it('blocks merchant restaurant activity until billing is active', function (): void {
    $data = createBookableRestaurant();
    actingRestaurantManager($data);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertStatus(402)
        ->assertJsonPath('billing.status', 'unpaid');

    MerchantSubscription::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => BillingPlan::query()->where('slug', 'foundation')->value('id'),
        'status' => MerchantSubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertOk();
});

it('lists invoice history and downloads invoice pdfs', function (): void {
    $data = createBookableRestaurant();
    actingRestaurantManager($data);

    $plan = BillingPlan::query()->where('slug', 'premium')->firstOrFail();
    MerchantSubscription::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'status' => MerchantSubscriptionStatus::Active,
        'current_period_end' => now()->addMonth(),
    ]);
    $invoice = MerchantInvoice::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'amount' => $plan->amount,
        'status' => MerchantInvoiceStatus::Paid,
        'paid_at' => now(),
    ]);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/invoices')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => 'paid',
        ]);

    $this->get('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/invoices/'.$invoice->id.'/download')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('processes signed paystack charge success webhooks', function (): void {
    $data = createBookableRestaurant();
    $plan = BillingPlan::query()->where('slug', 'foundation')->firstOrFail();
    MerchantInvoice::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'provider_reference' => 'mt_webhook',
        'amount' => $plan->amount,
        'status' => MerchantInvoiceStatus::Pending,
    ]);

    $payload = json_encode([
        'event' => 'charge.success',
        'data' => successfulPaystackTransaction('mt_webhook', $plan->amount),
    ], JSON_THROW_ON_ERROR);

    $this->withHeader('x-paystack-signature', hash_hmac('sha512', $payload, 'test-secret'))
        ->postJson('/api/v1/billing/paystack/webhook', json_decode($payload, true, 512, JSON_THROW_ON_ERROR))
        ->assertOk();

    $this->assertDatabaseHas('merchant_invoices', [
        'provider_reference' => 'mt_webhook',
        'status' => MerchantInvoiceStatus::Paid->value,
    ]);
});

function actingRestaurantManager(array $data): User
{
    $manager = User::factory()->create();
    assignScopedRole($manager, Role::PrincipalAdmin, $data['organization'], $data['restaurant']);
    Sanctum::actingAs($manager);

    return $manager;
}

/**
 * @return array<string, mixed>
 */
function successfulPaystackTransaction(string $reference, int $amount): array
{
    return [
        'id' => 123456,
        'status' => 'success',
        'reference' => $reference,
        'amount' => $amount,
        'currency' => 'NGN',
        'channel' => 'card',
        'paid_at' => now()->toISOString(),
        'gateway_response' => 'Successful',
        'customer' => [
            'customer_code' => 'CUS_card',
            'email' => 'billing@example.com',
        ],
        'authorization' => [
            'authorization_code' => 'AUTH_card',
            'reusable' => true,
            'brand' => 'visa',
            'card_type' => 'visa',
            'last4' => '4081',
            'exp_month' => '12',
            'exp_year' => '2030',
            'bin' => '408408',
            'bank' => 'TEST BANK',
            'signature' => 'SIG_card',
            'channel' => 'card',
        ],
        'subscription' => [
            'subscription_code' => 'SUB_card',
            'email_token' => 'email-token',
            'next_payment_date' => now()->addMonth()->toISOString(),
        ],
    ];
}

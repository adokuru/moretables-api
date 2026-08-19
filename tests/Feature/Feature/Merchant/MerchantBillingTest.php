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

it('allows a staff member with only billing.manage (no restaurants.view/manage) to view billing', function (): void {
    $data = createBookableRestaurant();
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $data['restaurant'], ['billing.manage']);
    Sanctum::actingAs($staff);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing')
        ->assertSuccessful();
});

it('forbids a staff member without billing.manage or restaurants.view/manage from viewing billing', function (): void {
    $data = createBookableRestaurant();
    $staff = User::factory()->create();
    grantAccessConfigPermissions($staff, $data['restaurant'], ['reservations.view']);
    Sanctum::actingAs($staff);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing')
        ->assertForbidden();
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

it('includes failed and pending invoices in payment history, not just paid ones', function (): void {
    $data = createBookableRestaurant();
    actingRestaurantManager($data);

    $plan = BillingPlan::query()->where('slug', 'premium')->firstOrFail();
    $failedInvoice = MerchantInvoice::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'amount' => $plan->amount,
        'status' => MerchantInvoiceStatus::Failed,
    ]);
    $pendingInvoice = MerchantInvoice::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'amount' => $plan->amount,
        'status' => MerchantInvoiceStatus::Pending,
    ]);

    $response = $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/invoices')
        ->assertOk();

    $response->assertJsonFragment(['id' => $failedInvoice->id, 'status' => 'failed']);
    $response->assertJsonFragment(['id' => $pendingInvoice->id, 'status' => 'pending']);
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

it('renews a subscription from a paystack invoice.update webhook', function (): void {
    $data = createBookableRestaurant();
    $plan = BillingPlan::query()->where('slug', 'foundation')->firstOrFail();

    $subscription = MerchantSubscription::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'status' => MerchantSubscriptionStatus::Active,
        'provider_subscription_code' => 'SUB_renewal',
        'current_period_end' => now()->addDay(),
        'next_payment_at' => now()->addDay(),
    ]);

    $nextPaymentDate = now()->addMonth()->addDay();

    $payload = json_encode([
        'event' => 'invoice.update',
        'data' => [
            'invoice_code' => 'INV_renewal',
            'amount' => $plan->amount,
            'currency' => 'NGN',
            'status' => 'success',
            'paid' => true,
            'paid_at' => now()->toISOString(),
            'customer' => ['customer_code' => 'CUS_card', 'email' => 'billing@example.com'],
            'authorization' => ['authorization_code' => 'AUTH_card', 'reusable' => true, 'last4' => '4081'],
            'subscription' => [
                'subscription_code' => 'SUB_renewal',
                'email_token' => 'email-token',
                'next_payment_date' => $nextPaymentDate->toISOString(),
            ],
            'transaction' => ['reference' => 'renewal_ref_1', 'status' => 'success'],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->withHeader('x-paystack-signature', hash_hmac('sha512', $payload, 'test-secret'))
        ->postJson('/api/v1/billing/paystack/webhook', json_decode($payload, true, 512, JSON_THROW_ON_ERROR))
        ->assertOk();

    $subscription->refresh();

    expect($subscription->status)->toBe(MerchantSubscriptionStatus::Active)
        ->and($subscription->current_period_end->toDateString())->toBe($nextPaymentDate->toDateString())
        ->and($subscription->next_payment_at->toDateString())->toBe($nextPaymentDate->toDateString());

    $this->assertDatabaseHas('merchant_invoices', [
        'provider_reference' => 'renewal_ref_1',
        'merchant_subscription_id' => $subscription->id,
        'status' => MerchantInvoiceStatus::Paid->value,
    ]);

    $this->assertDatabaseHas('merchant_payments', [
        'reference' => 'renewal_ref_1',
        'merchant_subscription_id' => $subscription->id,
        'status' => 'success',
    ]);
});

it('marks a subscription past due when the renewal charge is declined', function (): void {
    $data = createBookableRestaurant();
    $plan = BillingPlan::query()->where('slug', 'foundation')->firstOrFail();

    $subscription = MerchantSubscription::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'status' => MerchantSubscriptionStatus::Active,
        'provider_subscription_code' => 'SUB_failed',
    ]);

    $payload = json_encode([
        'event' => 'invoice.update',
        'data' => [
            'invoice_code' => 'INV_failed',
            'status' => 'failed',
            'paid' => false,
            'subscription' => ['subscription_code' => 'SUB_failed'],
            'transaction' => ['reference' => 'renewal_ref_failed', 'status' => 'failed'],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->withHeader('x-paystack-signature', hash_hmac('sha512', $payload, 'test-secret'))
        ->postJson('/api/v1/billing/paystack/webhook', json_decode($payload, true, 512, JSON_THROW_ON_ERROR))
        ->assertOk();

    $this->assertDatabaseMissing('merchant_invoices', ['provider_reference' => 'renewal_ref_failed']);

    expect($subscription->refresh()->status)->toBe(MerchantSubscriptionStatus::PastDue);
});

it('repairs a lapsed subscription from the provider when syncing', function (): void {
    $data = createBookableRestaurant();
    $plan = BillingPlan::query()->where('slug', 'foundation')->firstOrFail();

    $subscription = MerchantSubscription::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'status' => MerchantSubscriptionStatus::Expired,
        'provider_subscription_code' => 'SUB_lapsed',
        'current_period_end' => now()->subWeek(),
    ]);

    $nextPaymentDate = now()->addMonth();

    Http::fake([
        'https://api.paystack.co/subscription/SUB_lapsed' => Http::response([
            'status' => true,
            'data' => [
                'subscription_code' => 'SUB_lapsed',
                'status' => 'active',
                'next_payment_date' => $nextPaymentDate->toISOString(),
            ],
        ]),
    ]);

    $this->artisan('billing:sync-subscriptions')->assertSuccessful();

    $subscription->refresh();

    expect($subscription->status)->toBe(MerchantSubscriptionStatus::Active)
        ->and($subscription->current_period_end->toDateString())->toBe($nextPaymentDate->toDateString());
});

it('leaves admin assigned subscriptions alone when syncing', function (): void {
    $data = createBookableRestaurant();
    $plan = BillingPlan::query()->where('slug', 'foundation')->firstOrFail();

    $subscription = MerchantSubscription::factory()->create([
        'restaurant_id' => $data['restaurant']->id,
        'billing_plan_id' => $plan->id,
        'status' => MerchantSubscriptionStatus::Active,
        'provider_subscription_code' => 'admin_manual',
    ]);

    Http::fake();

    $this->artisan('billing:sync-subscriptions')->assertSuccessful();

    Http::assertNothingSent();
    expect($subscription->refresh()->status)->toBe(MerchantSubscriptionStatus::Active);
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

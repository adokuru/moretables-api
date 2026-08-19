<?php

use App\BillingPlanSlug;
use App\MerchantInvoiceStatus;
use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\MerchantSubscription;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\RestaurantStatus;
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

it('lets every restaurant of a business inherit the business subscription', function (): void {
    $data = createBookableRestaurant();
    $sibling = Restaurant::factory()->create(['organization_id' => $data['organization']->id]);
    actingBusinessOwner($data);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertStatus(402);

    activateBusinessBilling($data['organization'], 'premium');

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertOk();

    $this->getJson('/api/v1/merchant/restaurants/'.$sibling->id.'/tables')
        ->assertOk();

    $this->getJson('/api/v1/merchant/restaurants/'.$sibling->id.'/billing')
        ->assertOk()
        ->assertJsonPath('billing.is_active', true)
        ->assertJsonPath('billing.scope', 'business')
        ->assertJsonPath('billing.subscription.plan.slug', 'premium');
});

it('covers only as many restaurants as the plan allows, oldest first', function (): void {
    $data = createBookableRestaurant();
    $sibling = Restaurant::factory()->create(['organization_id' => $data['organization']->id]);
    actingBusinessOwner($data);

    activateBusinessBilling($data['organization'], 'foundation');

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertOk();

    $this->getJson('/api/v1/merchant/restaurants/'.$sibling->id.'/tables')
        ->assertStatus(402);
});

it('keeps serving a restaurant that bought its own subscription before the business had one', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);

    activateMerchantBilling($data['restaurant']);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertOk();

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing')
        ->assertOk()
        ->assertJsonPath('billing.scope', 'restaurant');
});

it('resolves plan tier gating through the business plan', function (): void {
    $data = createBookableRestaurant();
    activateBusinessBilling($data['organization'], 'core');

    expect($data['restaurant']->fresh()->hasPlanAtLeast(BillingPlanSlug::Core))->toBeTrue()
        ->and($data['restaurant']->fresh()->hasPlanAtLeast(BillingPlanSlug::Premium))->toBeFalse();
});

it('initializes a paystack checkout for the business', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/business',
                'access_code' => 'access-code',
            ],
        ]),
    ]);

    $this->postJson('/api/v1/merchant/businesses/'.$data['organization']->id.'/billing/checkout', [
        'plan' => 'premium',
    ])->assertCreated()
        ->assertJsonPath('checkout.authorization_url', 'https://checkout.paystack.com/business')
        ->assertJsonPath('invoice.scope', 'business')
        ->assertJsonPath('invoice.plan.slug', 'premium');

    expect(MerchantInvoice::query()
        ->whereNull('restaurant_id')
        ->where('organization_id', $data['organization']->id)
        ->exists())->toBeTrue();
});

it('rejects a business plan that cannot cover every restaurant the business owns', function (): void {
    $data = createBookableRestaurant();
    Restaurant::factory()->create(['organization_id' => $data['organization']->id]);
    actingBusinessOwner($data);

    $this->postJson('/api/v1/merchant/businesses/'.$data['organization']->id.'/billing/checkout', [
        'plan' => 'foundation',
    ])->assertStatus(422)
        ->assertJsonPath('restaurants_count', 2)
        ->assertJsonPath('restaurants_allowed', 1);
});

it('refuses a restaurant checkout when the business subscription already covers it', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);
    activateBusinessBilling($data['organization'], 'premium');

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/checkout', [
        'plan' => 'core',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'This restaurant is already covered by your business subscription.');
});

it('activates every restaurant of the business when a business checkout is verified', function (): void {
    $data = createBookableRestaurant();
    $sibling = Restaurant::factory()->create(['organization_id' => $data['organization']->id]);
    actingBusinessOwner($data);

    $plan = BillingPlan::query()->where('slug', 'premium')->firstOrFail();

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/business', 'access_code' => 'code'],
        ]),
    ]);

    $reference = $this->postJson('/api/v1/merchant/businesses/'.$data['organization']->id.'/billing/checkout', [
        'plan' => 'premium',
    ])->assertCreated()->json('checkout.reference');

    Http::fake([
        'https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => businessPaystackTransaction($reference, $plan->amount),
        ]),
    ]);

    $this->getJson('/api/v1/merchant/businesses/'.$data['organization']->id.'/billing/verify/'.$reference)
        ->assertOk()
        ->assertJsonPath('billing.is_active', true)
        ->assertJsonPath('billing.scope', 'business');

    $subscription = MerchantSubscription::query()
        ->whereNull('restaurant_id')
        ->where('organization_id', $data['organization']->id)
        ->firstOrFail();

    expect($subscription->status)->toBe(MerchantSubscriptionStatus::Active)
        ->and(MerchantInvoice::query()->whereNull('restaurant_id')->where('provider_reference', $reference)->value('status'))
        ->toBe(MerchantInvoiceStatus::Paid);

    $this->getJson('/api/v1/merchant/restaurants/'.$sibling->id.'/tables')->assertOk();
});

it('lists and downloads business invoices from a restaurant that inherits the subscription', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);

    $plan = BillingPlan::query()->where('slug', 'premium')->firstOrFail();
    activateBusinessBilling($data['organization'], 'premium');

    $invoice = MerchantInvoice::factory()
        ->forBusiness($data['organization'])
        ->create([
            'billing_plan_id' => $plan->id,
            'amount' => $plan->amount,
            'status' => MerchantInvoiceStatus::Paid,
            'paid_at' => now(),
        ]);

    $this->getJson('/api/v1/merchant/businesses/'.$data['organization']->id.'/billing/invoices')
        ->assertOk()
        ->assertJsonFragment(['invoice_number' => $invoice->invoice_number]);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/invoices')
        ->assertOk()
        ->assertJsonFragment(['invoice_number' => $invoice->invoice_number]);

    $this->get('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/invoices/'.$invoice->id.'/download')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('stops inheriting when billing scope is pinned back to the restaurant', function (): void {
    config(['billing.scope' => 'restaurant']);

    $data = createBookableRestaurant();
    actingBusinessOwner($data);
    activateBusinessBilling($data['organization'], 'premium');

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertStatus(402);
});

it('lists a restaurant publicly when its business subscription covers it', function (): void {
    $data = createBookableRestaurant();
    markRestaurantOnboardingComplete($data['restaurant']);
    $sibling = Restaurant::factory()->create([
        'organization_id' => $data['organization']->id,
        'status' => RestaurantStatus::Active,
    ]);
    markRestaurantOnboardingComplete($sibling);

    expect(Restaurant::publiclyListed()->pluck('id')->all())->toBe([]);

    activateBusinessBilling($data['organization'], 'foundation');

    expect(Restaurant::publiclyListed()->pluck('id')->all())->toBe([$data['restaurant']->id]);

    MerchantSubscription::query()->update(['billing_plan_id' => BillingPlan::query()->where('slug', 'premium')->value('id')]);

    expect(Restaurant::publiclyListed()->orderBy('id')->pluck('id')->all())
        ->toBe([$data['restaurant']->id, $sibling->id]);
});

/**
 * @return array<string, mixed>
 */
function businessPaystackTransaction(string $reference, int $amount): array
{
    return [
        'id' => 987654,
        'reference' => $reference,
        'status' => 'success',
        'amount' => $amount,
        'currency' => 'NGN',
        'channel' => 'card',
        'paid_at' => now()->toIso8601String(),
        'gateway_response' => 'Successful',
        'customer' => [
            'customer_code' => 'CUS_business',
            'email' => 'billing@business.test',
        ],
        'authorization' => [
            'authorization_code' => 'AUTH_business',
            'brand' => 'visa',
            'card_type' => 'visa debit',
            'last4' => '4081',
            'exp_month' => '12',
            'exp_year' => '2030',
            'bin' => '408408',
            'bank' => 'Test Bank',
            'signature' => 'SIG_business',
            'channel' => 'card',
            'reusable' => true,
        ],
        'subscription' => [
            'subscription_code' => 'SUB_business',
            'email_token' => 'token',
            'next_payment_date' => now()->addMonth()->toIso8601String(),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function actingBusinessOwner(array $data): User
{
    $owner = User::factory()->create();
    assignScopedRole($owner, Role::PrincipalAdmin, $data['organization']);
    Sanctum::actingAs($owner);

    return $owner;
}

it('refuses a restaurant upgrade when the business subscription already covers it', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);
    activateBusinessBilling($data['organization'], 'core');

    $this->postJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/billing/upgrade', [
        'plan' => 'premium',
    ])->assertStatus(422)
        ->assertJsonPath('business_id', $data['organization']->id);

    expect(MerchantSubscription::query()->whereNotNull('restaurant_id')->exists())->toBeFalse();
});

it('tells the merchant app which business a restaurant belongs to and who is billed', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);
    activateBusinessBilling($data['organization'], 'premium');

    $this->getJson('/api/v1/merchant/restaurants')
        ->assertOk()
        ->assertJsonPath('restaurants.0.organization_id', $data['organization']->id)
        ->assertJsonPath('restaurants.0.plan_slug', 'premium')
        ->assertJsonPath('restaurants.0.billing_scope', 'business');
});

it('downloads a business invoice from the business billing endpoint', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);

    $plan = BillingPlan::query()->where('slug', 'premium')->firstOrFail();
    $invoice = MerchantInvoice::factory()
        ->forBusiness($data['organization'])
        ->create([
            'billing_plan_id' => $plan->id,
            'amount' => $plan->amount,
            'status' => MerchantInvoiceStatus::Paid,
            'paid_at' => now(),
        ]);

    $this->get('/api/v1/merchant/businesses/'.$data['organization']->id.'/billing/invoices/'.$invoice->id.'/download')
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('reports the business subscription status when a business plan lapses', function (): void {
    $data = createBookableRestaurant();
    actingBusinessOwner($data);

    $subscription = activateBusinessBilling($data['organization'], 'premium');
    $subscription->update(['status' => MerchantSubscriptionStatus::Expired, 'current_period_end' => now()->subDay()]);

    $this->getJson('/api/v1/merchant/restaurants/'.$data['restaurant']->id.'/tables')
        ->assertStatus(402)
        ->assertJsonPath('billing.status', 'expired');
});

<?php

namespace App\Console\Commands;

use App\Models\MerchantSubscription;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SyncSubscriptions extends Command
{
    protected $signature = 'billing:sync-subscriptions';

    protected $description = 'Repair subscription status and billing period from the payment provider';

    public function handle(BillingService $billingService): void
    {
        $synced = 0;
        $failed = 0;

        MerchantSubscription::query()
            ->where('provider_subscription_code', 'like', 'SUB%')
            ->chunkById(100, function (Collection $subscriptions) use ($billingService, &$synced, &$failed): void {
                foreach ($subscriptions as $subscription) {
                    try {
                        $billingService->syncSubscriptionFromProvider($subscription);
                        $synced++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("{$subscription->provider_subscription_code}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("Synced {$synced} subscription(s), {$failed} failed.");
    }
}

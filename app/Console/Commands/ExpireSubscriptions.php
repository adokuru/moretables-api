<?php

namespace App\Console\Commands;

use App\MerchantSubscriptionStatus;
use App\Models\MerchantSubscription;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'billing:expire-subscriptions';

    protected $description = 'Mark subscriptions as expired when their billing period has ended';

    public function handle(): void
    {
        $expired = MerchantSubscription::query()
            ->whereIn('status', [
                MerchantSubscriptionStatus::Active->value,
                MerchantSubscriptionStatus::Trialing->value,
                MerchantSubscriptionStatus::PastDue->value,
            ])
            ->where('current_period_end', '<', now())
            ->update([
                'status'     => MerchantSubscriptionStatus::Expired,
                'canceled_at' => now(),
            ]);

        $this->info("Expired {$expired} subscription(s).");
    }
}

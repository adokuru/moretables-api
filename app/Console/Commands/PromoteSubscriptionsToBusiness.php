<?php

namespace App\Console\Commands;

use App\BillingScope;
use App\MerchantSubscriptionStatus;
use App\Models\MerchantSubscription;
use App\Models\Organization;
use App\Services\PerformanceCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves subscriptions that were sold per-restaurant up to the business that owns them, so paying
 * merchants land on the model the rest of the application now bills on.
 *
 * A subscription is only promoted where doing so cannot change who is being served: the business
 * must hold exactly one live restaurant-level subscription, have no business-level one already,
 * and own no more restaurants than that subscription's plan allows. Anything else is reported and
 * left exactly as it is — those are commercial decisions (which restaurant keeps the plan, who gets
 * refunded), not ones this command should make.
 */
class PromoteSubscriptionsToBusiness extends Command
{
    protected $signature = 'billing:promote-subscriptions-to-business
                            {--dry-run : Report what would change without writing anything}
                            {--organization= : Restrict the run to a single organization id}';

    protected $description = 'Promote restaurant-level subscriptions to the business that owns them, where it is unambiguous';

    public function handle(PerformanceCacheService $performanceCache): int
    {
        if (! BillingScope::businessBillingEnabled()) {
            $this->error('Business billing is disabled (billing.scope). Nothing to promote.');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $promoted = 0;
        $skipped = [];

        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($query, $id) => $query->whereKey($id))
            ->whereHas('restaurants.billingSubscriptions', fn ($query) => $query->whereIn('status', $this->liveStatuses()))
            ->withCount('restaurants')
            ->with('restaurants:id,organization_id')
            ->get();

        foreach ($organizations as $organization) {
            $subscriptions = MerchantSubscription::query()
                ->with('plan')
                ->where('organization_id', $organization->id)
                ->whereNotNull('restaurant_id')
                ->whereIn('status', $this->liveStatuses())
                ->get();

            if ($subscriptions->count() > 1) {
                $skipped[] = [$organization->name, $subscriptions->count().' live restaurant subscriptions — decide which one the business keeps'];

                continue;
            }

            $subscription = $subscriptions->first();

            if (! $subscription) {
                continue;
            }

            if ($organization->billingSubscriptions()->whereIn('status', $this->liveStatuses())->exists()) {
                $skipped[] = [$organization->name, 'already has a business subscription'];

                continue;
            }

            $plan = $subscription->plan;

            if (! $plan) {
                $skipped[] = [$organization->name, 'subscription has no plan'];

                continue;
            }

            if (! $plan->allowsRestaurantCount($organization->restaurants_count)) {
                $skipped[] = [
                    $organization->name,
                    "{$plan->name} covers {$plan->max_restaurants} restaurant(s), business has {$organization->restaurants_count}",
                ];

                continue;
            }

            $this->line("Promoting {$organization->name}: {$plan->name} (restaurant #{$subscription->restaurant_id} → business)");
            $promoted++;

            if ($isDryRun) {
                continue;
            }

            DB::transaction(function () use ($subscription, $organization, $performanceCache): void {
                $subscription->update([
                    'restaurant_id' => null,
                    'organization_id' => $organization->id,
                    'metadata' => [
                        ...($subscription->metadata ?? []),
                        'promoted_from_restaurant_id' => $subscription->restaurant_id,
                        'promoted_at' => now()->toIso8601String(),
                    ],
                ]);

                $performanceCache->invalidateBusinessBillingEligibility($organization->id);
            });
        }

        foreach ($skipped as [$name, $reason]) {
            $this->warn("Skipped {$name}: {$reason}");
        }

        $this->info($isDryRun
            ? "Dry run: {$promoted} subscription(s) would be promoted, ".count($skipped).' skipped.'
            : "Promoted {$promoted} subscription(s) to business level, ".count($skipped).' skipped.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function liveStatuses(): array
    {
        return [
            MerchantSubscriptionStatus::Active->value,
            MerchantSubscriptionStatus::Trialing->value,
            MerchantSubscriptionStatus::PastDue->value,
        ];
    }
}

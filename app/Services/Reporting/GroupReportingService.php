<?php

namespace App\Services\Reporting;

use App\BillingPlanSlug;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantReview;
use App\Models\User;
use App\ReservationStatus;
use Illuminate\Http\Request;

class GroupReportingService
{
    public function __construct(private readonly ReportingFilterService $filters) {}

    /** @return array{can_view: bool, can_export: bool} */
    public function access(User $user, Organization $organization): array
    {
        $qualifies = $organization->activeBillingSubscription()->whereHas('plan', fn ($q) => $q->where('slug', BillingPlanSlug::Premium->value))->exists()
            && $organization->restaurants()->count() >= 2;

        return [
            'can_view' => $qualifies && ($user->hasPermission('reservations.view', organization: $organization)
                || $user->hasPermission('audit_logs.view', organization: $organization)),
            'can_export' => $qualifies && ($user->hasPermission('reporting.export', organization: $organization)
                || $user->hasPermission('restaurants.manage', organization: $organization)),
        ];
    }

    /** @return array{restaurants: array, data: array} */
    public function report(Organization $organization, array $params): array
    {
        $restaurants = $organization->restaurants()->orderBy('name')->orderBy('id')->get();
        $rows = $restaurants->map(function (Restaurant $restaurant) use ($params): array {
            $context = $this->filters->resolveContext(new Request($params), $restaurant);
            $covers = (int) $this->filters->baseQuery($restaurant, $context)
                ->whereIn('status', [ReservationStatus::Seated, ReservationStatus::Completed])
                ->sum('party_size');
            $reviews = RestaurantReview::query()->where('restaurant_id', $restaurant->id)
                ->where('visited_at', '>=', $context->periodStartUtc->setTimezone($context->timezone)->toDateString())
                ->where('visited_at', '<', $context->periodEndUtc->setTimezone($context->timezone)->toDateString())
                ->selectRaw('COUNT(*) as review_count, AVG(rating) as average_rating')->first();

            return [
                'id' => $restaurant->id,
                'restaurant' => $restaurant->name,
                'overallRating' => $reviews->average_rating === null ? null : (float) $reviews->average_rating,
                'seatedCovers' => $covers,
                'guestSpend' => null,
                'perCover' => null,
                '_reviewCount' => (int) $reviews->review_count,
            ];
        });
        $comparison = isset($params['compare_restaurant_id']) ? $rows->firstWhere('id', (int) $params['compare_restaurant_id']) : null;
        $reviewCount = $rows->sum('_reviewCount');
        $baselineRating = $comparison !== null ? $comparison['overallRating'] : ($reviewCount > 0
            ? $rows->sum(fn ($row) => ($row['overallRating'] ?? 0) * $row['_reviewCount']) / $reviewCount : null);
        $baselineCovers = $comparison !== null ? $comparison['seatedCovers'] : $rows->avg('seatedCovers');

        $data = $rows->when(isset($params['restaurant_id']), fn ($items) => $items->where('id', (int) $params['restaurant_id']))
            ->map(function (array $row) use ($baselineRating, $baselineCovers): array {
                unset($row['_reviewCount']);
                $row['overallRatingChange'] = $this->change($row['overallRating'], $baselineRating);
                $row['seatedCoversChange'] = $this->change($row['seatedCovers'], $baselineCovers);
                $row['guestSpendChange'] = null;
                $row['perCoverChange'] = null;
                $row['overallRating'] = $row['overallRating'] === null ? null : round($row['overallRating'], 1);

                return $row;
            })->values()->all();

        return ['restaurants' => $restaurants->map->only(['id', 'name'])->all(), 'data' => $data];
    }

    private function change(int|float|null $value, int|float|null $baseline): ?float
    {
        return $value === null || $baseline === null || (float) $baseline === 0.0
            ? null : round(($value - $baseline) / $baseline * 100, 1);
    }

    public function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Restaurant', 'Overall Rating', 'Change (%)', 'Seated Covers', 'Change (%)', 'Guest Spend', 'Change (%)', 'Per Cover', 'Change (%)']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['restaurant'], $row['overallRating'] ?? '—', $row['overallRatingChange'] ?? '—',
                $row['seatedCovers'], $row['seatedCoversChange'] ?? '—', $row['guestSpend'] ?? '—',
                $row['guestSpendChange'] ?? '—', $row['perCover'] ?? '—', $row['perCoverChange'] ?? '—',
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
}

<?php

namespace App\Models;

use App\Enums\CancellationPolicyManagementMethod;
use App\Enums\CancellationPolicyPartySizeScope;
use Database\Factories\RestaurantCancellationPolicyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantCancellationPolicy extends Model
{
    /** @use HasFactory<RestaurantCancellationPolicyFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'name',
        'management_method',
        'party_size_scope',
        'min_party_size',
        'max_party_size',
        'hold_charge_amount',
        'starts_on',
        'ends_on',
        'days',
        'start_time',
        'end_time',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'management_method' => CancellationPolicyManagementMethod::class,
            'party_size_scope' => CancellationPolicyPartySizeScope::class,
            'min_party_size' => 'integer',
            'max_party_size' => 'integer',
            'hold_charge_amount' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @param  Builder<RestaurantCancellationPolicy>  $query
     * @return Builder<RestaurantCancellationPolicy>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function appliesToPartySize(int $partySize, ?int $largePartyThreshold = null): bool
    {
        return match ($this->party_size_scope) {
            CancellationPolicyPartySizeScope::AllPartySizes => true,
            CancellationPolicyPartySizeScope::LargePartiesOnly => $largePartyThreshold !== null && $partySize >= $largePartyThreshold,
            CancellationPolicyPartySizeScope::Custom => $partySize >= ($this->min_party_size ?? 1)
                && $partySize <= ($this->max_party_size ?? PHP_INT_MAX),
        };
    }
}

<?php

namespace App\Models;

use App\BillingPlanSlug;
use App\RestaurantStatus;
use App\Services\RestaurantRewardRuleService;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Restaurant extends Model implements HasMedia
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'status',
        'is_featured',
        'rewards_enabled',
        'reservation_reward_points',
        'email',
        'phone',
        'city',
        'state',
        'country',
        'timezone',
        'address_line_1',
        'address_line_2',
        'latitude',
        'longitude',
        'description',
        'internal_notes',
        'website',
        'instagram_handle',
        'average_price_range',
        'dining_style',
        'dress_code',
        'total_seating_capacity',
        'number_of_tables',
        'menu_source',
        'menu_link',
        'executive_chef',
        'dietary_options',
        'beverage_options',
        'amenities',
        'smoking_policy',
        'delivery_options',
        'safety_policies',
        'parking_info',
        'closest_landmark',
        'private_events_info',
        'catering_info',
        'payment_options',
        'accessibility_features',
        'onboarding_progress',
        'onboarding_current_step',
        'onboarding_last_step',
        'is_profile_published',
        'contact_email_verified_at',
        'contact_phone_verified_at',
        'widget_settings',
        'display_recommended_table_assignment',
        'display_guest_full_name',
        'show_guest_preferences',
        'show_cleaned_tables',
    ];

    /**
     * Defaults for the embeddable reservation widget. Stored settings are
     * merged over these so older rows and partial updates stay complete.
     *
     * @var array<string, mixed>
     */
    public const WIDGET_SETTINGS_DEFAULTS = [
        'enabled' => true,
        'theme' => 'light',
        'accent_color' => '#AF8C59',
        'layout' => 'card',
        'button_text' => 'Book a table',
    ];

    /**
     * @return array<string, mixed>
     */
    public function widgetSettingsWithDefaults(): array
    {
        $stored = is_array($this->widget_settings) ? $this->widget_settings : [];

        return array_merge(
            self::WIDGET_SETTINGS_DEFAULTS,
            array_intersect_key($stored, self::WIDGET_SETTINGS_DEFAULTS),
        );
    }

    protected function casts(): array
    {
        return [
            'status' => RestaurantStatus::class,
            'is_featured' => 'boolean',
            'rewards_enabled' => 'boolean',
            'reservation_reward_points' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'total_seating_capacity' => 'integer',
            'number_of_tables' => 'integer',
            'dietary_options' => 'array',
            'beverage_options' => 'array',
            'amenities' => 'array',
            'smoking_policy' => 'array',
            'safety_policies' => 'array',
            'payment_options' => 'array',
            'accessibility_features' => 'array',
            'onboarding_progress' => 'array',
            'widget_settings' => 'array',
            'display_recommended_table_assignment' => 'boolean',
            'display_guest_full_name' => 'boolean',
            'show_guest_preferences' => 'boolean',
            'show_cleaned_tables' => 'boolean',
            'is_profile_published' => 'boolean',
            'contact_email_verified_at' => 'datetime',
            'contact_phone_verified_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(CuisineOption::class, 'cuisine_option_restaurant')
            ->withPivot('is_primary')
            ->withTimestamps()
            ->orderByDesc('cuisine_option_restaurant.is_primary')
            ->orderBy('cuisine_options.name');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(RestaurantHour::class);
    }

    public function policy(): HasOne
    {
        return $this->hasOne(RestaurantPolicy::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(RestaurantMenuItem::class)->orderBy('sort_order');
    }

    public function diningAreas(): HasMany
    {
        return $this->hasMany(DiningArea::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(RestaurantServer::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function guestSurveys(): HasMany
    {
        return $this->hasMany(GuestSurvey::class);
    }

    public function rewardRules(): HasMany
    {
        return $this->hasMany(RestaurantRewardRule::class);
    }

    /**
     * Whether the restaurant participates in the MoreTables credits (loyalty rewards) program.
     */
    public function offersMoretablesCredits(): bool
    {
        return (bool) $this->rewards_enabled;
    }

    /**
     * Whether the restaurant has set its own reservation reward points instead of relying on the default.
     */
    public function hasCustomReservationRewardPoints(): bool
    {
        return $this->reservation_reward_points !== null
            && (int) $this->reservation_reward_points !== RestaurantRewardRuleService::DEFAULT_POINTS;
    }

    public function cancellationPolicies(): HasMany
    {
        return $this->hasMany(RestaurantCancellationPolicy::class)->orderBy('sort_order');
    }

    public function tableCombinations(): HasMany
    {
        return $this->hasMany(TableCombination::class);
    }

    public function guestContacts(): HasMany
    {
        return $this->hasMany(GuestContact::class)->orderBy('first_name')->orderBy('last_name');
    }

    public function views(): HasMany
    {
        return $this->hasMany(RestaurantView::class);
    }

    public function savedEntries(): HasMany
    {
        return $this->hasMany(SavedRestaurant::class);
    }

    public function listItems(): HasMany
    {
        return $this->hasMany(UserRestaurantListItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RestaurantReview::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    public function shiftNotes(): HasMany
    {
        return $this->hasMany(RestaurantShiftNote::class);
    }

    public function guestCommunicationSetting(): HasOne
    {
        return $this->hasOne(GuestCommunicationSetting::class);
    }

    public function billingSubscriptions(): HasMany
    {
        return $this->hasMany(MerchantSubscription::class);
    }

    public function latestBillingSubscription(): HasOne
    {
        return $this->hasOne(MerchantSubscription::class)->latestOfMany();
    }

    public function activeBillingSubscription(): HasOne
    {
        return $this->hasOne(MerchantSubscription::class)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q): void {
                $q->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->latestOfMany();
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(MerchantPaymentMethod::class);
    }

    /**
     * Whether this restaurant's active (or trialing) subscription is on the given
     * plan tier or higher. Used to gate plan-tier features (e.g. survey customization)
     * separately from role-based permissions.
     */
    public function hasPlanAtLeast(BillingPlanSlug $minimum): bool
    {
        return $this->activeBillingSubscription?->plan?->slug?->atLeast($minimum) === true;
    }

    /**
     * The best-known billing contact email for this restaurant, skipping blank
     * (not just null) values so a stray empty string doesn't block the fallback chain.
     */
    public function billingEmail(): ?string
    {
        foreach ([
            $this->organization?->billing_email,
            $this->organization?->business_email,
            $this->email,
            $this->organization?->primary_contact_email,
        ] as $candidate) {
            if (filled($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function defaultPaymentMethod(): HasOne
    {
        return $this->hasOne(MerchantPaymentMethod::class)
            ->where('is_default', true)
            ->latestOfMany();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MerchantInvoice::class);
    }

    public function socialHandles(): HasMany
    {
        return $this->hasMany(RestaurantSocialHandle::class)->orderBy('platform');
    }

    public function availabilityPeriods(): HasMany
    {
        return $this->hasMany(RestaurantAvailabilityPeriod::class)->orderBy('sort_order');
    }

    public function availabilitySchedules(): HasMany
    {
        return $this->hasMany(RestaurantAvailabilitySchedule::class);
    }

    public function specialDays(): HasMany
    {
        return $this->hasMany(RestaurantSpecialDay::class)->orderBy('date');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(RestaurantShift::class)
            ->orderBy('day_of_week')
            ->orderBy('sort_order')
            ->orderBy('starts_at');
    }

    public function galleryCategories(): HasMany
    {
        return $this->hasMany(RestaurantGalleryCategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function menuCategories(): HasMany
    {
        return $this->hasMany(RestaurantMenuCategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(RestaurantInternalNote::class)->latest();
    }

    public function accessConfigs(): HasMany
    {
        return $this->hasMany(RestaurantAccessConfig::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * @param  Builder<Restaurant>  $query
     * @return Builder<Restaurant>
     */
    public function scopePubliclyListed(Builder $query): Builder
    {
        return $query
            ->where('status', RestaurantStatus::Active->value)
            ->where('is_profile_published', true)
            ->where(function (Builder $bookableQuery): void {
                $bookableQuery
                    ->whereHas('shifts', fn (Builder $shiftQuery): Builder => $shiftQuery->where('is_active', true))
                    ->orWhereHas('availabilitySchedules')
                    ->orWhereHas('hours', fn (Builder $hoursQuery): Builder => $hoursQuery->where('is_closed', false));
            })
            ->whereHas('activeBillingSubscription');
    }

    public function isPubliclyListed(): bool
    {
        if ($this->status !== RestaurantStatus::Active) {
            return false;
        }

        if (! $this->is_profile_published) {
            return false;
        }

        if (! $this->hasBookableTimes()) {
            return false;
        }

        if ($this->relationLoaded('activeBillingSubscription')) {
            return $this->activeBillingSubscription !== null;
        }

        return $this->activeBillingSubscription()->exists();
    }

    private function hasBookableTimes(): bool
    {
        $hasActiveShift = $this->relationLoaded('shifts')
            ? $this->shifts->contains(fn (RestaurantShift $shift): bool => $shift->is_active)
            : $this->shifts()->where('is_active', true)->exists();

        if ($hasActiveShift) {
            return true;
        }

        $hasAvailabilitySchedule = $this->relationLoaded('availabilitySchedules')
            ? $this->availabilitySchedules->isNotEmpty()
            : $this->availabilitySchedules()->exists();

        if ($hasAvailabilitySchedule) {
            return true;
        }

        return $this->relationLoaded('hours')
            ? $this->hours->contains(fn (RestaurantHour $hour): bool => ! $hour->is_closed)
            : $this->hours()->where('is_closed', false)->exists();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('featured')->singleFile();
        $this->addMediaCollection('gallery');
        $this->addMediaCollection('menu_documents')
            ->useDisk('public')
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 320, 240)
            ->performOnCollections('featured', 'gallery');

        $this->addMediaConversion('card')
            ->fit(Fit::Crop, 900, 640)
            ->performOnCollections('featured', 'gallery');
    }
}

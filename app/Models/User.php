<?php

namespace App\Models;

use App\UserAuthMethod;
use App\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'status',
        'auth_method',
        'last_active_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'auth_method' => UserAuthMethod::class,
            'last_active_at' => 'datetime',
        ];
    }

    public function authChallenges(): HasMany
    {
        return $this->hasMany(AuthChallenge::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function restaurantViews(): HasMany
    {
        return $this->hasMany(RestaurantView::class);
    }

    public function savedRestaurantEntries(): HasMany
    {
        return $this->hasMany(SavedRestaurant::class);
    }

    public function restaurantLists(): HasMany
    {
        return $this->hasMany(UserRestaurantList::class);
    }

    public function restaurantReviews(): HasMany
    {
        return $this->hasMany(RestaurantReview::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    public function expoPushTokens(): HasMany
    {
        return $this->hasMany(ExpoPushToken::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot(['organization_id', 'restaurant_id', 'assigned_by', 'scope_type'])
            ->withTimestamps();
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->last_name]))) ?: $this->name;
    }

    public function hasRole(
        string $roleName,
        ?Restaurant $restaurant = null,
        ?Organization $organization = null,
    ): bool {
        return $this->roleAssignments()
            ->whereHas('role', fn ($query) => $query->where('name', $roleName))
            ->when($restaurant, fn ($query) => $query->where('restaurant_id', $restaurant->getKey()))
            ->when($organization, fn ($query) => $query->where('organization_id', $organization->getKey()))
            ->exists();
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function hasAnyRole(
        array $roleNames,
        ?Restaurant $restaurant = null,
        ?Organization $organization = null,
    ): bool {
        return $this->roleAssignments()
            ->whereHas('role', fn ($query) => $query->whereIn('name', $roleNames))
            ->when($restaurant, fn ($query) => $query->where('restaurant_id', $restaurant->getKey()))
            ->when($organization, fn ($query) => $query->where('organization_id', $organization->getKey()))
            ->exists();
    }

    public function canManageRestaurant(Restaurant $restaurant): bool
    {
        return $this->hasAnyRole(
            [
                Role::OrganizationOwner,
                Role::RestaurantManager,
                Role::RestaurantStaff,
                Role::BusinessAdmin,
                Role::DevAdmin,
                Role::SuperAdmin,
            ],
            restaurant: $restaurant,
        ) || $this->hasAnyRole(
            [
                Role::OrganizationOwner,
                Role::BusinessAdmin,
                Role::DevAdmin,
                Role::SuperAdmin,
            ],
            organization: $restaurant->organization,
        );
    }

    public function requiresTwoFactor(): bool
    {
        return $this->requiresStaffLogin() || $this->requiresAdminLogin();
    }

    public function requiresStaffLogin(): bool
    {
        return $this->hasAnyRole([
            Role::OrganizationOwner,
            Role::RestaurantManager,
            Role::RestaurantStaff,
        ]);
    }

    public function requiresAdminLogin(): bool
    {
        return $this->hasAnyRole([
            Role::BusinessAdmin,
            Role::DevAdmin,
            Role::SuperAdmin,
        ]);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }
}

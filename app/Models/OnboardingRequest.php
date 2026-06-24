<?php

namespace App\Models;

use App\OnboardingContactReason;
use App\OnboardingJobTitle;
use App\OnboardingLocationCount;
use App\OnboardingRequestStatus;
use Database\Factories\OnboardingRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingRequest extends Model
{
    /** @use HasFactory<OnboardingRequestFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'first_name',
        'last_name',
        'restaurant_name',
        'owner_name',
        'email',
        'phone',
        'job_title',
        'location_count',
        'contact_reason',
        'address',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnboardingRequestStatus::class,
            'job_title' => OnboardingJobTitle::class,
            'location_count' => OnboardingLocationCount::class,
            'contact_reason' => OnboardingContactReason::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

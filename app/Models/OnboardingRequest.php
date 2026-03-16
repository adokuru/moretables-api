<?php

namespace App\Models;

use App\OnboardingRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingRequest extends Model
{
    /** @use HasFactory<\Database\Factories\OnboardingRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_name',
        'owner_name',
        'email',
        'phone',
        'address',
        'notes',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OnboardingRequestStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

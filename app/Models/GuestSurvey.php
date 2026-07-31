<?php

namespace App\Models;

use Database\Factories\GuestSurveyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestSurvey extends Model
{
    /** @use HasFactory<GuestSurveyFactory> */
    use HasFactory;

    protected $fillable = [
        'scope',
        'restaurant_id',
        'version',
        'publication_sequence',
        'title',
        'description',
        'logo_url',
        'status',
        'questions',
        'send_delay_minutes',
        'channels',
        'published_at',
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'publication_sequence' => 'integer',
            'questions' => 'array',
            'channels' => 'array',
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
        ];
    }

    public function isPlatformScoped(): bool
    {
        return $this->scope === 'platform';
    }

    public function isRestaurantScoped(): bool
    {
        return $this->scope === 'restaurant';
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(GuestSurveyInvitation::class);
    }

    public function adminDispatches(): HasMany
    {
        return $this->hasMany(AdminSurveyDispatch::class);
    }
}

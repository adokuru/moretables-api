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

    protected $fillable = ['restaurant_id', 'version', 'publication_sequence', 'title', 'description', 'logo_url', 'status', 'questions', 'send_delay_minutes', 'channels', 'published_at'];

    protected function casts(): array
    {
        return ['publication_sequence' => 'integer', 'questions' => 'array', 'channels' => 'array', 'published_at' => 'datetime'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(GuestSurveyInvitation::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\GuestSurveyInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GuestSurveyInvitation extends Model
{
    /** @use HasFactory<GuestSurveyInvitationFactory> */
    use HasFactory;

    protected $fillable = ['guest_survey_id', 'reservation_id', 'token_hash', 'expires_at', 'delivery_claimed_at', 'sent_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'delivery_claimed_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(GuestSurvey::class, 'guest_survey_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(GuestSurveyResponse::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\GuestSurveyResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestSurveyResponse extends Model
{
    /** @use HasFactory<GuestSurveyResponseFactory> */
    use HasFactory;

    protected $fillable = ['guest_survey_invitation_id', 'answers', 'submitted_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'submitted_at' => 'datetime'];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(GuestSurveyInvitation::class, 'guest_survey_invitation_id');
    }
}

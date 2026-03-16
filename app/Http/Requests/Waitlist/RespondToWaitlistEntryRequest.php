<?php

namespace App\Http\Requests\Waitlist;

use Illuminate\Foundation\Http\FormRequest;

class RespondToWaitlistEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}

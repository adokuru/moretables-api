<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UploadMoretableLineupCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cover' => ['required', 'image', 'max:10240'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cover.required' => 'Please upload a cover image.',
            'cover.image' => 'The cover must be an image file.',
            'cover.max' => 'The cover image may not be larger than 10MB.',
        ];
    }
}

<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\HasMediaUploadFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UploadModelMediaRequest extends FormRequest
{
    use HasMediaUploadFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->mediaUploadRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasFile('featured_image') && ! $this->hasFile('gallery_images')) {
                $validator->errors()->add('featured_image', 'Please upload a featured image or at least one gallery image.');
            }
        });
    }
}

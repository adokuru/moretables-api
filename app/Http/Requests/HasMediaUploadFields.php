<?php

namespace App\Http\Requests;

trait HasMediaUploadFields
{
    protected function mediaUploadRules(): array
    {
        return [
            'featured_image' => ['nullable', 'image', 'max:10240'],
            'featured_image_alt_text' => ['nullable', 'string', 'max:255'],
            'gallery_images' => ['nullable', 'array'],
            'gallery_images.*' => ['image', 'max:10240'],
            'gallery_image_alt_texts' => ['nullable', 'array'],
            'gallery_image_alt_texts.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}

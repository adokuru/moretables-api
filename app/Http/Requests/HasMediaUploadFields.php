<?php

namespace App\Http\Requests;

trait HasMediaUploadFields
{
    protected function mediaUploadRules(): array
    {
        return [
            'featured_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'featured_image_alt_text' => ['nullable', 'string', 'max:255'],
            'gallery_images' => ['nullable', 'array'],
            'gallery_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'gallery_image_alt_texts' => ['nullable', 'array'],
            'gallery_image_alt_texts.*' => ['nullable', 'string', 'max:255'],
            'gallery_category_ids' => ['nullable', 'array'],
            'gallery_category_ids.*' => ['nullable', 'integer'],
        ];
    }
}

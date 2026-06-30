<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RestaurantUploadStagingService
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function hasDeferredUploads(array $validated): bool
    {
        if (($validated['featured_image'] ?? null) instanceof UploadedFile) {
            return true;
        }

        if (($validated['menu_document'] ?? null) instanceof UploadedFile) {
            return true;
        }

        foreach ($validated['gallery_images'] ?? [] as $galleryImage) {
            if ($galleryImage instanceof UploadedFile) {
                return true;
            }
        }

        if (data_get($validated, 'menu.pdf') instanceof UploadedFile) {
            return true;
        }

        foreach (data_get($validated, 'menu.categories', []) as $category) {
            foreach ($category['items'] ?? [] as $item) {
                if (($item['image'] ?? null) instanceof UploadedFile) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function stage(array $validated): array
    {
        $token = (string) Str::uuid();
        $basePath = "tmp/restaurant-uploads/{$token}";
        $disk = Storage::disk('local');
        $staged = $validated;
        $staged['__staging_base_path'] = $basePath;

        foreach (['featured_image', 'menu_document'] as $key) {
            if (($validated[$key] ?? null) instanceof UploadedFile) {
                $staged[$key] = $disk->putFile($basePath, $validated[$key]);
            }
        }

        if (isset($validated['gallery_images']) && is_array($validated['gallery_images'])) {
            $staged['gallery_images'] = [];

            foreach ($validated['gallery_images'] as $index => $galleryImage) {
                if ($galleryImage instanceof UploadedFile) {
                    $staged['gallery_images'][$index] = $disk->putFile($basePath, $galleryImage);
                }
            }
        }

        if (data_get($validated, 'menu.pdf') instanceof UploadedFile) {
            $staged['menu']['pdf'] = $disk->putFile($basePath, data_get($validated, 'menu.pdf'));
            $staged['menu_document'] = $staged['menu']['pdf'];
        }

        foreach (data_get($staged, 'menu.categories', []) as $categoryIndex => $category) {
            foreach ($category['items'] ?? [] as $itemIndex => $item) {
                $image = data_get($validated, "menu.categories.{$categoryIndex}.items.{$itemIndex}.image");

                if ($image instanceof UploadedFile) {
                    $staged['menu']['categories'][$categoryIndex]['items'][$itemIndex]['image'] = $disk->putFile($basePath, $image);
                }
            }
        }

        return $staged;
    }

    /**
     * @param  array<string, mixed>  $staged
     * @return array<string, mixed>
     */
    public function hydrateUploadedFiles(array $staged): array
    {
        $payload = $staged;
        $disk = Storage::disk('local');

        foreach (['featured_image', 'menu_document'] as $key) {
            if (is_string($payload[$key] ?? null) && $disk->exists($payload[$key])) {
                $payload[$key] = $this->uploadedFileFromPath($disk->path($payload[$key]));
            }
        }

        if (isset($payload['gallery_images']) && is_array($payload['gallery_images'])) {
            foreach ($payload['gallery_images'] as $index => $path) {
                if (is_string($path) && $disk->exists($path)) {
                    $payload['gallery_images'][$index] = $this->uploadedFileFromPath($disk->path($path));
                }
            }
        }

        if (is_string(data_get($payload, 'menu.pdf')) && $disk->exists(data_get($payload, 'menu.pdf'))) {
            $path = data_get($payload, 'menu.pdf');
            $payload['menu']['pdf'] = $this->uploadedFileFromPath($disk->path($path));
            $payload['menu_document'] = $payload['menu']['pdf'];
        }

        foreach (data_get($payload, 'menu.categories', []) as $categoryIndex => $category) {
            foreach ($category['items'] ?? [] as $itemIndex => $item) {
                $path = data_get($item, 'image');

                if (is_string($path) && $disk->exists($path)) {
                    $payload['menu']['categories'][$categoryIndex]['items'][$itemIndex]['image'] = $this->uploadedFileFromPath($disk->path($path));
                }
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $staged
     */
    public function cleanup(array $staged): void
    {
        $basePath = $staged['__staging_base_path'] ?? null;

        if (! is_string($basePath) || $basePath === '') {
            return;
        }

        Storage::disk('local')->deleteDirectory($basePath);
    }

    protected function uploadedFileFromPath(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            basename($path),
            null,
            null,
            true,
        );
    }
}

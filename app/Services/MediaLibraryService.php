<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaLibraryService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncUploadedMedia(Model&HasMedia $model, array $payload): void
    {
        $featuredImage = $payload['featured_image'] ?? null;

        if ($featuredImage instanceof UploadedFile) {
            $this->addUploadedFileToCollection($model, $featuredImage, 'featured', [
                'alt_text' => $payload['featured_image_alt_text'] ?? null,
            ]);
        }

        foreach ($payload['gallery_images'] ?? [] as $index => $galleryImage) {
            if (! $galleryImage instanceof UploadedFile) {
                continue;
            }

            $rawCategoryId = $payload['gallery_category_ids'][$index] ?? null;
            $this->addUploadedFileToCollection($model, $galleryImage, 'gallery', [
                'alt_text' => $payload['gallery_image_alt_texts'][$index] ?? null,
                'gallery_category_id' => $rawCategoryId !== null ? (int) $rawCategoryId : null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $customProperties
     */
    public function addUploadedFileToCollection(
        Model&HasMedia $model,
        UploadedFile $file,
        string $collectionName,
        array $customProperties = [],
    ): Media {
        $fileAdder = $model
            ->addMedia($file)
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        if ($customProperties !== []) {
            $fileAdder->withCustomProperties($customProperties);
        }

        return $fileAdder->toMediaCollection($collectionName);
    }

    public function syncMenuDocument(Model&HasMedia $model, ?UploadedFile $menuDocument): ?Media
    {
        if (! $menuDocument instanceof UploadedFile) {
            return null;
        }

        return $this->addUploadedFileToCollection($model, $menuDocument, 'menu_documents');
    }

    public function updateMedia(Model&HasMedia $model, Media $media, ?string $altText, mixed $galleryCategoryId = '__unchanged__'): Media
    {
        $this->ensureOwnedMedia($model, $media);

        $media->setCustomProperty('alt_text', $altText);

        if ($galleryCategoryId !== '__unchanged__') {
            if ($galleryCategoryId === null) {
                $media->forgetCustomProperty('gallery_category_id');
            } else {
                $media->setCustomProperty('gallery_category_id', (int) $galleryCategoryId);
            }
        }

        $media->save();

        return $media->refresh();
    }

    public function featureMedia(Model&HasMedia $model, Media $media): Media
    {
        $this->ensureOwnedMedia($model, $media);

        return $model
            ->copyMedia($media->getPath())
            ->usingName($media->name)
            ->usingFileName($media->file_name)
            ->withCustomProperties($media->custom_properties)
            ->toMediaCollection('featured');
    }

    /**
     * @param  array<int, int>  $mediaIds
     */
    public function reorderGallery(Model&HasMedia $model, array $mediaIds): void
    {
        $galleryMedia = $model->loadMissing('media')
            ->media
            ->where('collection_name', 'gallery')
            ->pluck('id')
            ->all();

        $unknownMediaIds = array_diff($mediaIds, $galleryMedia);

        abort_if($unknownMediaIds !== [], 404, 'One or more media items were not found.');

        Media::setNewOrder($mediaIds);
    }

    public function clearGalleryCategoryFromMedia(Model&HasMedia $model, int $categoryId): void
    {
        $model->loadMissing('media')
            ->media
            ->where('collection_name', 'gallery')
            ->filter(fn (Media $m) => $m->getCustomProperty('gallery_category_id') === $categoryId)
            ->each(function (Media $m): void {
                $m->forgetCustomProperty('gallery_category_id');
                $m->save();
            });
    }

    public function deleteMedia(Model&HasMedia $model, Media $media): void
    {
        $this->ensureOwnedMedia($model, $media);

        $media->delete();
    }

    public function ensureOwnedMedia(Model&HasMedia $model, Media $media): void
    {
        abort_unless(
            $media->model_type === $model::class && (string) $media->model_id === (string) $model->getKey(),
            404,
        );
    }
}

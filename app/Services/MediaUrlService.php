<?php

namespace App\Services;

use Illuminate\Support\Facades\URL;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaUrlService
{
    /**
     * @var list<string>
     */
    private const SIGNED_DOWNLOAD_COLLECTIONS = [
        'menu_documents',
    ];

    public function originalUrl(Media $media): string
    {
        if ($this->shouldUseSignedDownload($media)) {
            return URL::temporarySignedRoute(
                'media.download',
                now()->addDay(),
                ['media' => $media->id],
            );
        }

        return $media->getUrl();
    }

    protected function shouldUseSignedDownload(Media $media): bool
    {
        return in_array($media->collection_name, self::SIGNED_DOWNLOAD_COLLECTIONS, true);
    }
}

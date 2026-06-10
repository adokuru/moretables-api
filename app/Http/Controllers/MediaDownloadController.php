<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaDownloadController extends Controller
{
    /**
     * @var list<string>
     */
    private const DOWNLOADABLE_COLLECTIONS = [
        'menu_documents',
    ];

    public function show(Media $media): BinaryFileResponse|Response
    {
        abort_unless(
            in_array($media->collection_name, self::DOWNLOADABLE_COLLECTIONS, true),
            404,
        );

        abort_unless(is_file($media->getPath()), 404);

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="'.$media->file_name.'"',
        ]);
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaAssetResource extends JsonResource
{
    /** @mixin Media */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'file_name' => $this->file_name,
            'collection' => $this->collection_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'order' => $this->order_column,
            'featured' => $this->collection_name === 'featured',
            'alt_text' => $this->getCustomProperty('alt_text'),
            'original_url' => $this->getUrl(),
            'thumb_url' => $this->getAvailableUrl(['thumb']),
            'card_url' => $this->getAvailableUrl(['card']),
        ];
    }
}

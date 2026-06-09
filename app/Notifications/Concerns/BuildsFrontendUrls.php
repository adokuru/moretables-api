<?php

namespace App\Notifications\Concerns;

trait BuildsFrontendUrls
{
    protected function frontendBaseUrl(): string
    {
        return rtrim(config('app.frontend_urls.main') ?: config('app.url'), '/');
    }

    protected function restaurantUrl(?string $slug): string
    {
        return $slug !== null && $slug !== ''
            ? $this->frontendBaseUrl().'/restaurants/'.$slug
            : $this->frontendBaseUrl();
    }
}

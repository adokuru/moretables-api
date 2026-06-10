<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Http\UploadedFile;

class RestaurantMenuSyncService
{
    public function __construct(
        protected MediaLibraryService $mediaLibraryService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function sync(Restaurant $restaurant, array $validated): void
    {
        $menuMode = $this->resolveMenuMode($validated);

        if ($menuMode === null) {
            return;
        }

        $menuLink = $menuMode === 'link' ? $this->resolveMenuLink($validated) : null;

        $restaurant->update([
            'menu_source' => $menuMode,
            'menu_link' => $menuLink,
        ]);

        match ($menuMode) {
            'link' => $this->syncLinkMenu($restaurant),
            'pdf' => $this->syncPdfMenu($restaurant, $validated),
            'manual' => $this->syncManualMenu($restaurant, $validated),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function resolveMenuMode(array $validated): ?string
    {
        $mode = data_get($validated, 'menu.mode') ?? ($validated['menu_source'] ?? null);

        if (! is_string($mode) || $mode === '') {
            return null;
        }

        return $mode;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function resolveMenuLink(array $validated): ?string
    {
        $link = data_get($validated, 'menu.link') ?? ($validated['menu_link'] ?? null);

        if (! is_string($link)) {
            return null;
        }

        $trimmed = trim($link);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function syncLinkMenu(Restaurant $restaurant): void
    {
        $this->clearManualMenuItems($restaurant);
        $restaurant->clearMediaCollection('menu_documents');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function syncPdfMenu(Restaurant $restaurant, array $validated): void
    {
        $this->clearManualMenuItems($restaurant);

        $menuDocument = data_get($validated, 'menu.pdf')
            ?? ($validated['menu_document'] ?? null);

        if ($menuDocument instanceof UploadedFile) {
            $this->mediaLibraryService->syncMenuDocument($restaurant, $menuDocument);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function syncManualMenu(Restaurant $restaurant, array $validated): void
    {
        $restaurant->clearMediaCollection('menu_documents');

        $menu = $validated['menu'] ?? [];
        $currency = strtoupper((string) data_get($menu, 'currency', 'NGN'));

        if (filled(data_get($menu, 'categories'))) {
            $this->syncCategorizedManualMenu($restaurant, data_get($menu, 'categories', []), $currency);

            return;
        }

        $this->syncLegacyManualMenu($restaurant, $menu, $currency);
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     */
    protected function syncCategorizedManualMenu(Restaurant $restaurant, array $categories, string $currency): void
    {
        $submittedIds = [];
        $sortOrder = 0;

        foreach ($categories as $category) {
            $sectionName = (string) ($category['name'] ?? '');

            foreach ($category['items'] ?? [] as $item) {
                $attributes = [
                    'section_name' => $sectionName,
                    'item_name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'price' => $this->normalizeMenuPrice($item['price']),
                    'currency' => $currency,
                    'sort_order' => $sortOrder,
                    'is_featured' => $this->normalizeBooleanFlag($item['is_featured'] ?? false),
                ];

                $sortOrder++;

                if (! empty($item['id'])) {
                    $menuItem = $restaurant->menuItems()->whereKey($item['id'])->first();
                }

                if (! isset($menuItem)) {
                    $menuItem = $restaurant->menuItems()->create($attributes);
                } else {
                    $menuItem->update($attributes);
                }

                $submittedIds[] = $menuItem->id;

                $image = $item['image'] ?? null;

                if ($image instanceof UploadedFile) {
                    $this->mediaLibraryService->syncUploadedMedia($menuItem, [
                        'featured_image' => $image,
                    ]);
                }

                unset($menuItem);
            }
        }

        $this->deleteUnsubmittedMenuItems($restaurant, $submittedIds);
    }

    /**
     * @param  array<string, mixed>  $menu
     */
    protected function syncLegacyManualMenu(Restaurant $restaurant, array $menu, string $currency): void
    {
        $submittedIds = [];

        foreach (data_get($menu, 'items', []) as $index => $item) {
            $menuItem = $restaurant->menuItems()->create([
                'section_name' => data_get($menu, 'name'),
                'item_name' => $item['name'],
                'description' => $item['description'] ?? null,
                'price' => $this->normalizeMenuPrice($item['price']),
                'currency' => $currency,
                'sort_order' => $index,
                'is_featured' => false,
            ]);

            $submittedIds[] = $menuItem->id;
        }

        $this->deleteUnsubmittedMenuItems($restaurant, $submittedIds);
    }

    /**
     * @param  list<int>  $submittedIds
     */
    protected function deleteUnsubmittedMenuItems(Restaurant $restaurant, array $submittedIds): void
    {
        $restaurant->menuItems()
            ->when($submittedIds !== [], fn ($query) => $query->whereNotIn('id', $submittedIds))
            ->when($submittedIds === [], fn ($query) => $query)
            ->each(function ($menuItem): void {
                $menuItem->clearMediaCollection('featured');
                $menuItem->delete();
            });
    }

    protected function clearManualMenuItems(Restaurant $restaurant): void
    {
        $restaurant->menuItems()->each(function ($menuItem): void {
            $menuItem->clearMediaCollection('featured');
            $menuItem->delete();
        });
    }

    protected function normalizeMenuPrice(mixed $price): float
    {
        return (float) $price;
    }

    protected function normalizeBooleanFlag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

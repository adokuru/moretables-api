<?php

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

trait ValidatesAdminRestaurantMenu
{
    /**
     * @return array<string, mixed>
     */
    protected function adminRestaurantMenuRules(): array
    {
        return [
            'menu' => ['nullable', 'array'],
            'menu.mode' => ['nullable', Rule::in(['link', 'pdf', 'manual'])],
            'menu.link' => ['nullable', 'url', 'max:2048'],
            'menu.pdf' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:20480'],
            'menu.name' => ['nullable', 'string', 'max:100'],
            'menu.currency' => ['nullable', 'string', 'max:10'],
            'menu.items' => ['nullable', 'array', 'min:1'],
            'menu.items.*.name' => ['required_with:menu.items', 'string', 'max:255'],
            'menu.items.*.description' => ['nullable', 'string'],
            'menu.items.*.price' => ['required_with:menu.items', 'numeric', 'min:0'],
            'menu.categories' => ['nullable', 'array', 'min:1'],
            'menu.categories.*.name' => ['required_with:menu.categories', 'string', 'max:100'],
            'menu.categories.*.items' => ['required_with:menu.categories', 'array', 'min:1'],
            'menu.categories.*.items.*.id' => ['nullable', 'integer'],
            'menu.categories.*.items.*.name' => ['required', 'string', 'max:255'],
            'menu.categories.*.items.*.description' => ['nullable', 'string', 'max:200'],
            'menu.categories.*.items.*.price' => ['required', 'numeric', 'min:0'],
            'menu.categories.*.items.*.is_featured' => ['nullable', Rule::in(['0', '1', 0, 1, true, false])],
            'menu.categories.*.items.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function adminRestaurantFrontendAliasRules(): array
    {
        return [
            'cuisine_type' => ['nullable', 'string', 'max:100'],
            'restaurant_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'restaurant_photos' => ['nullable', 'array', 'max:10'],
            'restaurant_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'booking_window_days' => ['nullable', 'integer', 'min:1'],
            'reservation_duration_minutes' => ['nullable', 'integer', 'min:30'],
        ];
    }

    protected function prepareAdminRestaurantFrontendPayload(): void
    {
        $merged = [];

        if ($this->filled('cuisine_type') && ! $this->has('cuisines')) {
            $cuisineType = trim((string) $this->input('cuisine_type'));

            if ($cuisineType !== '') {
                $merged['cuisines'] = [$cuisineType];
            }
        }

        if ($this->has('booking_window_days') || $this->has('reservation_duration_minutes')) {
            $policy = $this->input('policy', []);

            if ($this->has('booking_window_days')) {
                $policy['booking_window_days'] = $this->input('booking_window_days');
            }

            if ($this->has('reservation_duration_minutes')) {
                $policy['reservation_duration_minutes'] = $this->input('reservation_duration_minutes');
            }

            $merged['policy'] = $policy;
        }

        if ($this->hasFile('restaurant_logo')) {
            $merged['featured_image'] = $this->file('restaurant_logo');
        }

        if ($this->has('restaurant_photos')) {
            $photos = $this->file('restaurant_photos') ?? $this->input('restaurant_photos');

            if (is_array($photos)) {
                $merged['gallery_images'] = $photos;
            }
        }

        $menuMode = $this->input('menu.mode') ?? $this->input('menu_source');

        if ($menuMode !== null) {
            $menu = array_merge($this->input('menu', []), ['mode' => $menuMode]);
            $merged['menu'] = $menu;
            $merged['menu_source'] = $menuMode;
        }

        $menuLink = $this->input('menu.link') ?? $this->input('menu_link');

        if (filled($menuLink)) {
            $normalizedLink = $this->normalizeAdminRestaurantUrl((string) $menuLink);
            $merged['menu_link'] = $normalizedLink;
            $merged['menu'] = array_merge($merged['menu'] ?? $this->input('menu', []), [
                'link' => $normalizedLink,
            ]);
        }

        if ($this->hasFile('menu.pdf')) {
            $merged['menu_document'] = $this->file('menu.pdf');
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedWithMenuUploads($key = null, $default = null): mixed
    {
        $validated = $this->validated($key, $default);

        if ($key !== null) {
            return $validated;
        }

        if (! is_array($validated)) {
            return $validated;
        }

        return $this->mergeMenuCategoryImageUploads($validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function mergeMenuCategoryImageUploads(array $validated): array
    {
        if (data_get($validated, 'menu.mode') !== 'manual') {
            return $validated;
        }

        $categories = data_get($validated, 'menu.categories');

        if (! is_array($categories)) {
            return $validated;
        }

        foreach ($categories as $categoryIndex => &$category) {
            foreach ($category['items'] ?? [] as $itemIndex => &$item) {
                $image = $this->file("menu.categories.$categoryIndex.items.$itemIndex.image");

                if ($image instanceof UploadedFile) {
                    $item['image'] = $image;
                }
            }
        }

        $validated['menu']['categories'] = $categories;

        $menuPdf = $this->file('menu.pdf');

        if ($menuPdf instanceof UploadedFile) {
            $validated['menu']['pdf'] = $menuPdf;
            $validated['menu_document'] = $menuPdf;
        }

        return $validated;
    }

    protected function validateAdminRestaurantMenu(Validator $validator): void
    {
        if (! $this->hasAnyMenuInput()) {
            return;
        }

        $menuMode = $this->input('menu.mode') ?? $this->input('menu_source');

        if ($menuMode === 'link' && blank($this->input('menu.link')) && blank($this->input('menu_link'))) {
            $validator->errors()->add('menu.link', 'A menu link is required when using link mode.');
        }

        if ($menuMode === 'pdf' && ! $this->hasMenuPdfForValidation()) {
            $validator->errors()->add('menu.pdf', 'A menu PDF is required when using PDF mode.');
        }

        if ($menuMode !== 'manual') {
            return;
        }

        $hasCategories = filled($this->input('menu.categories'));

        if ($hasCategories) {
            if (blank($this->input('menu.currency'))) {
                $validator->errors()->add('menu.currency', 'A menu currency is required for manual menu entry.');
            }

            return;
        }

        if (blank($this->input('menu.name'))) {
            $validator->errors()->add('menu.name', 'A menu name is required for manual menu entry.');
        }

        if (blank($this->input('menu.currency'))) {
            $validator->errors()->add('menu.currency', 'A menu currency is required for manual menu entry.');
        }

        $menuItems = $this->input('menu.items', []);

        if (! is_array($menuItems) || $menuItems === []) {
            $validator->errors()->add('menu.items', 'At least one manual menu item is required.');
        }
    }

    protected function hasAnyMenuInput(): bool
    {
        return $this->has('menu')
            || $this->filled('menu_source')
            || $this->filled('menu_link');
    }

    protected function hasMenuPdfForValidation(): bool
    {
        if ($this->hasFile('menu.pdf') || $this->hasFile('menu_document')) {
            return true;
        }

        $restaurant = $this->route('restaurant');

        if ($restaurant !== null && $restaurant->getMedia('menu_documents')->isNotEmpty()) {
            return true;
        }

        return false;
    }

    protected function normalizeAdminRestaurantUrl(string $url): string
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return $trimmed;
        }

        if (! preg_match('/^https?:\/\//i', $trimmed)) {
            return 'https://'.$trimmed;
        }

        return $trimmed;
    }
}

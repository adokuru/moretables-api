<?php

namespace App\Http\Requests\Admin;

use App\Models\OnboardingRequest;
use App\Models\Role;
use App\RestaurantStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdminBusinessOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasAnyRole([
            Role::BusinessAdmin,
            Role::DevAdmin,
            Role::SuperAdmin,
        ]);
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'business_slug' => ['nullable', 'string', 'max:255', Rule::unique('organizations', 'slug')],
            'business_phone' => ['required', 'string', 'max:30'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'business_email' => ['required', 'email', 'max:255'],
            'business_website' => ['required', 'url', 'max:2048'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'business_city' => ['nullable', 'string', 'max:100'],
            'business_state' => ['nullable', 'string', 'max:100'],
            'business_country' => ['nullable', 'string', 'max:100'],
            'onboarding_request_id' => ['nullable', 'integer', 'exists:onboarding_requests,id'],
            'restaurants_count' => ['required', 'integer', 'min:1'],
            'restaurants' => ['required', 'array', 'min:1'],
            'restaurants.*.name' => ['required', 'string', 'max:255'],
            'restaurants.*.slug' => ['nullable', 'string', 'max:255', Rule::unique('restaurants', 'slug')],
            'restaurants.*.status' => ['nullable', Rule::enum(RestaurantStatus::class)],
            'restaurants.*.is_featured' => ['nullable', 'boolean'],
            'restaurants.*.email' => ['required', 'email', 'max:255'],
            'restaurants.*.phone' => ['required', 'string', 'max:30'],
            'restaurants.*.description' => ['nullable', 'string'],
            'restaurants.*.website' => ['nullable', 'url', 'max:2048'],
            'restaurants.*.instagram_handle' => ['nullable', 'string', 'max:255'],
            'restaurants.*.cuisine_type' => ['required', 'string', 'max:100'],
            'restaurants.*.average_price_range' => ['required', 'string', 'max:100'],
            'restaurants.*.dining_style' => ['required', 'string', 'max:100'],
            'restaurants.*.dress_code' => ['required', 'string', 'max:100'],
            'restaurants.*.country' => ['nullable', 'string', 'max:100'],
            'restaurants.*.city' => ['nullable', 'string', 'max:100'],
            'restaurants.*.state' => ['nullable', 'string', 'max:100'],
            'restaurants.*.address_line_1' => ['required', 'string', 'max:255'],
            'restaurants.*.address_line_2' => ['nullable', 'string', 'max:255'],
            'restaurants.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'restaurants.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'restaurants.*.hours' => ['nullable', 'array'],
            'restaurants.*.hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'restaurants.*.hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'restaurants.*.hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'restaurants.*.hours.*.is_closed' => ['nullable', 'boolean'],
            'restaurants.*.accessibility_features' => ['nullable', 'array'],
            'restaurants.*.accessibility_features.*' => ['string', 'max:120'],
            'restaurants.*.payment_options' => ['nullable', 'array'],
            'restaurants.*.payment_options.*' => ['string', 'max:50'],
            'restaurants.*.restaurant_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'restaurants.*.restaurant_photos' => ['nullable', 'array', 'max:10'],
            'restaurants.*.restaurant_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'restaurants.*.total_seating_capacity' => ['nullable', 'integer', 'min:1'],
            'restaurants.*.number_of_tables' => ['nullable', 'integer', 'min:1'],
            'restaurants.*.booking_window_days' => ['nullable', 'integer', 'min:1'],
            'restaurants.*.reservation_duration_minutes' => ['nullable', 'integer', 'min:30'],
            'restaurants.*.menu' => ['nullable', 'array'],
            'restaurants.*.menu.mode' => ['required_with:restaurants.*.menu', Rule::in(['link', 'pdf', 'manual'])],
            'restaurants.*.menu.link' => ['nullable', 'url', 'max:2048'],
            'restaurants.*.menu.pdf' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:20480'],
            'restaurants.*.menu.name' => ['nullable', 'string', 'max:100'],
            'restaurants.*.menu.currency' => ['nullable', 'string', 'max:10'],
            'restaurants.*.menu.items' => ['nullable', 'array', 'min:1'],
            'restaurants.*.menu.items.*.name' => ['required_with:restaurants.*.menu.items', 'string', 'max:255'],
            'restaurants.*.menu.items.*.description' => ['nullable', 'string'],
            'restaurants.*.menu.items.*.price' => ['required_with:restaurants.*.menu.items', 'numeric', 'min:0'],
            'restaurants.*.menu.categories' => ['nullable', 'array', 'min:1'],
            'restaurants.*.menu.categories.*.name' => ['required_with:restaurants.*.menu.categories', 'string', 'max:100'],
            'restaurants.*.menu.categories.*.items' => ['required_with:restaurants.*.menu.categories', 'array', 'min:1'],
            'restaurants.*.menu.categories.*.items.*.name' => ['required', 'string', 'max:255'],
            'restaurants.*.menu.categories.*.items.*.description' => ['nullable', 'string', 'max:200'],
            'restaurants.*.menu.categories.*.items.*.price' => ['required', 'numeric', 'min:0'],
            'restaurants.*.menu.categories.*.items.*.is_featured' => ['required', Rule::in(['0', '1', 0, 1, true, false])],
            'restaurants.*.menu.categories.*.items.*.image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function onboardingPayload(): array
    {
        $payload = $this->validated();

        foreach ($payload['restaurants'] as $index => &$restaurant) {
            if (data_get($restaurant, 'menu.mode') !== 'manual') {
                continue;
            }

            $categories = data_get($restaurant, 'menu.categories');

            if (! is_array($categories)) {
                continue;
            }

            foreach ($categories as $categoryIndex => &$category) {
                foreach ($category['items'] ?? [] as $itemIndex => &$item) {
                    $image = $this->file("restaurants.$index.menu.categories.$categoryIndex.items.$itemIndex.image");

                    if ($image !== null) {
                        $item['image'] = $image;
                    }
                }
            }

            $restaurant['menu']['categories'] = $categories;
        }

        return $payload;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $restaurants = $this->input('restaurants', []);
                $plannedCount = (int) $this->input('restaurants_count');
                $submittedCount = count($restaurants);

                if ($submittedCount < 1) {
                    $validator->errors()->add('restaurants', 'At least one restaurant must be submitted.');
                }

                if ($submittedCount > $plannedCount) {
                    $validator->errors()->add(
                        'restaurants_count',
                        'The restaurants count cannot be less than the number of submitted restaurants.',
                    );
                }

                if ($this->filled('onboarding_request_id')) {
                    $onboardingRequest = OnboardingRequest::query()->find($this->integer('onboarding_request_id'));

                    if ($onboardingRequest?->organization_id !== null) {
                        $validator->errors()->add(
                            'onboarding_request_id',
                            'This onboarding request has already been linked to an organization.',
                        );
                    }
                }

                $providedSlugs = collect($restaurants)
                    ->pluck('slug')
                    ->filter()
                    ->values();

                if ($providedSlugs->duplicates()->isNotEmpty()) {
                    $validator->errors()->add('restaurants', 'Each restaurant slug must be unique within this request.');
                }

                foreach ($restaurants as $index => $restaurant) {
                    $this->validateRestaurantHours($validator, $restaurant, $index);
                    $this->validateRestaurantTableSetup($validator, $restaurant, $index);
                    $this->validateRestaurantMenu($validator, $restaurant, $index);
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.required' => 'A business name is required.',
            'business_phone.required' => 'A business phone number is required.',
            'owner_name.required' => 'An owner or manager name is required.',
            'owner_phone.required' => 'An owner or manager phone number is required.',
            'owner_email.required' => 'An owner email address is required so we can create the owner account.',
            'business_email.required' => 'A business email address is required.',
            'business_website.required' => 'A business website is required.',
            'restaurants_count.required' => 'Please specify how many restaurants are being onboarded.',
            'restaurants.required' => 'At least one restaurant entry is requ ired.',
            'restaurants.*.name.required' => 'Each restaurant needs a name.',
            'restaurants.*.email.required' => 'Each restaurant needs an email address.',
            'restaurants.*.phone.required' => 'Each restaurant needs a phone number.',
            'restaurants.*.cuisine_type.required' => 'Each restaurant needs a cuisine type.',
            'restaurants.*.average_price_range.required' => 'Each restaurant needs an average price range.',
            'restaurants.*.dining_style.required' => 'Each restaurant needs a dining style.',
            'restaurants.*.dress_code.required' => 'Each restaurant needs a dress code.',
            'restaurants.*.address_line_1.required' => 'Each restaurant needs a location or address.',
            'restaurants.*.latitude.required' => 'Each restaurant needs a latitude.',
            'restaurants.*.longitude.required' => 'Each restaurant needs a longitude.',
            'restaurants.*.menu.mode.required' => 'Each restaurant needs a menu input mode.',
        ];
    }

    /**
     * @param  array<string, mixed>  $restaurant
     */
    protected function validateRestaurantHours(Validator $validator, array $restaurant, int $index): void
    {
        $hours = collect($restaurant['hours'] ?? []);

        if ($hours->pluck('day_of_week')->duplicates()->isNotEmpty()) {
            $validator->errors()->add(
                "restaurants.$index.hours",
                'Each restaurant can only define one schedule per day of the week.',
            );
        }

        foreach ($hours as $hourIndex => $hour) {
            $isClosed = (bool) ($hour['is_closed'] ?? false);
            $opensAt = $hour['opens_at'] ?? null;
            $closesAt = $hour['closes_at'] ?? null;

            if (! $isClosed && (blank($opensAt) || blank($closesAt))) {
                $validator->errors()->add(
                    "restaurants.$index.hours.$hourIndex",
                    'Open days must include both opening and closing times.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $restaurant
     */
    protected function validateRestaurantTableSetup(Validator $validator, array $restaurant, int $index): void
    {
        $totalSeatingCapacity = (int) ($restaurant['total_seating_capacity'] ?? 0);
        $numberOfTables = (int) ($restaurant['number_of_tables'] ?? 0);

        if ($totalSeatingCapacity > 0 && $numberOfTables > 0 && $totalSeatingCapacity < $numberOfTables) {
            $validator->errors()->add(
                "restaurants.$index.total_seating_capacity",
                'Total seating capacity must be greater than or equal to the number of tables.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $restaurant
     */
    protected function validateRestaurantMenu(Validator $validator, array $restaurant, int $index): void
    {
        $menuMode = data_get($restaurant, 'menu.mode');

        if ($menuMode === 'link' && blank(data_get($restaurant, 'menu.link'))) {
            $validator->errors()->add("restaurants.$index.menu.link", 'A menu link is required when using link mode.');
        }

        if ($menuMode === 'pdf' && ! $this->file("restaurants.$index.menu.pdf")) {
            $validator->errors()->add("restaurants.$index.menu.pdf", 'A menu PDF is required when using PDF mode.');
        }

        if ($menuMode !== 'manual') {
            return;
        }

        $hasCategories = filled(data_get($restaurant, 'menu.categories'));

        if ($hasCategories) {
            $categories = data_get($restaurant, 'menu.categories', []);

            if (! is_array($categories) || $categories === []) {
                $validator->errors()->add("restaurants.$index.menu.categories", 'At least one menu category is required for manual menu entry.');

                return;
            }

            if (blank(data_get($restaurant, 'menu.currency'))) {
                $validator->errors()->add("restaurants.$index.menu.currency", 'A menu currency is required for manual menu entry.');
            }

            return;
        }

        if (blank(data_get($restaurant, 'menu.name'))) {
            $validator->errors()->add("restaurants.$index.menu.name", 'A menu name is required for manual menu entry.');
        }

        if (blank(data_get($restaurant, 'menu.currency'))) {
            $validator->errors()->add("restaurants.$index.menu.currency", 'A menu currency is required for manual menu entry.');
        }

        $menuItems = data_get($restaurant, 'menu.items', []);

        if (! is_array($menuItems) || $menuItems === []) {
            $validator->errors()->add("restaurants.$index.menu.items", 'At least one manual menu item is required.');
        }
    }
}

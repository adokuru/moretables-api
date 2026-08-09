<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\ValidatesRestaurantCancellationPolicyAttributes;
use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;

class StoreRestaurantCancellationPolicyRequest extends FormRequest
{
    use ValidatesRestaurantCancellationPolicyAttributes;

    public function authorize(): bool
    {
        $restaurant = $this->route('restaurant');

        return $restaurant instanceof Restaurant
            && (bool) $this->user()?->hasAnyRestaurantPermission(['restaurants.manage', 'policies.manage'], $restaurant);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->cancellationPolicyRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->cancellationPolicyMessages();
    }
}

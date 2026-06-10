<?php

namespace App\Http\Requests\Merchant;

use App\Http\Requests\Merchant\Concerns\AuthorizesRestaurantManageOnboarding;
use App\Http\Requests\Merchant\Concerns\ValidatesRestaurantCancellationPolicyAttributes;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantCancellationPolicyRequest extends FormRequest
{
    use AuthorizesRestaurantManageOnboarding;
    use ValidatesRestaurantCancellationPolicyAttributes;

    public function authorize(): bool
    {
        return $this->authorizeRestaurantManageOnboarding();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->cancellationPolicyRules(isUpdate: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->cancellationPolicyMessages();
    }
}

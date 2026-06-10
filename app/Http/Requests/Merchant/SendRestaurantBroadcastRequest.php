<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendRestaurantBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $restaurant = $this->route('restaurant');

        return [
            'title' => ['required', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:1000'],
            'audience' => ['required', 'string', Rule::in(['all', 'selected'])],
            'guest_contact_ids' => ['required_if:audience,selected', 'prohibited_if:audience,all', 'array', 'min:1', 'max:500'],
            'guest_contact_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('guest_contacts', 'id')->where('restaurant_id', $restaurant?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'A broadcast title is required.',
            'message.required' => 'A broadcast message is required.',
            'audience.in' => 'The audience must be either "all" or "selected".',
            'guest_contact_ids.required_if' => 'Select at least one guest when broadcasting to selected guests.',
            'guest_contact_ids.prohibited_if' => 'Guest contacts cannot be provided when broadcasting to all guests.',
            'guest_contact_ids.*.exists' => 'One or more selected guests do not belong to this restaurant.',
        ];
    }
}

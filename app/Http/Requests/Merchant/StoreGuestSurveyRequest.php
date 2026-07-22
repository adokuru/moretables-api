<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreGuestSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'template_key' => ['sometimes', 'string', Rule::in(['post_dining', 'blank'])],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'questions' => ['sometimes', 'array', 'max:50'],
            'questions.*.id' => ['required', 'string', 'alpha_dash', 'max:50', 'distinct'],
            'questions.*.type' => ['required', 'string', Rule::in(['rating', 'yes_no', 'nps', 'single_choice', 'long_text'])],
            'questions.*.prompt' => ['required', 'string', 'max:500'],
            'questions.*.required' => ['required', 'boolean'],
            'questions.*.options' => ['present', 'array', 'max:10'],
            'questions.*.options.*' => ['string', 'max:160', 'distinct'],
            'send_delay_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'channels' => ['sometimes', 'array', 'min:1', 'max:2'],
            'channels.*' => ['string', 'distinct', Rule::in(['email', 'push'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'questions.*.id.distinct' => 'Each question must have a unique id.',
            'channels.min' => 'Select at least one delivery channel.',
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ($this->input('questions', []) as $index => $question) {
                if (($question['type'] ?? null) !== 'single_choice') {
                    continue;
                }

                $options = array_values(array_unique(array_filter(
                    $question['options'] ?? [],
                    fn (mixed $option): bool => is_string($option) && trim($option) !== '',
                )));

                if (count($options) < 2) {
                    $validator->errors()->add("questions.{$index}.options", 'Single-choice questions require at least two unique nonblank options.');
                }
            }
        }];
    }
}

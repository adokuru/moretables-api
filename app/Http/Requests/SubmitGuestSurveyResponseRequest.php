<?php

namespace App\Http\Requests;

use App\Models\GuestSurveyInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SubmitGuestSurveyResponseRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'answers' => collect($this->input('answers', []))->map(function (mixed $answer): mixed {
                if (is_array($answer) && is_string($answer['value'] ?? null)) {
                    $answer['value'] = trim($answer['value']);
                }

                return $answer;
            })->all(),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:50'],
            'answers.*.question_id' => ['required', 'string', 'max:50', 'distinct'],
            'answers.*.value' => ['present'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'answers.required' => 'Provide at least one survey answer.',
            'answers.min' => 'Provide at least one survey answer.',
            'answers.*.question_id.required' => 'Every answer must identify its question.',
            'answers.*.question_id.distinct' => 'Each question may only be answered once.',
            'answers.*.value.present' => 'Every answer must include a value.',
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $token = (string) $this->route('token');
            $invitation = GuestSurveyInvitation::query()
                ->with('survey')
                ->where('token_hash', hash('sha256', $token))
                ->where('expires_at', '>', now())
                ->first();

            if (! $invitation) {
                return;
            }

            $questions = collect($invitation->survey->questions)->keyBy('id');
            $answers = collect($this->input('answers', []))->keyBy('question_id');

            foreach ($questions as $id => $question) {
                if (($question['required'] ?? false) && ! $answers->has($id)) {
                    $validator->errors()->add('answers', "An answer is required for {$question['prompt']}.");
                }
            }

            foreach ($answers as $id => $answer) {
                $question = $questions->get($id);

                if (! $question) {
                    $validator->errors()->add('answers', "Question {$id} is not part of this survey.");

                    continue;
                }

                $value = $answer['value'] ?? null;
                $valid = match ($question['type']) {
                    'rating' => is_int($value) && $value >= 1 && $value <= 5,
                    'nps' => is_int($value) && $value >= 0 && $value <= 10,
                    'yes_no' => is_bool($value),
                    'single_choice' => is_string($value) && in_array($value, $question['options'] ?? [], true),
                    'long_text' => is_string($value) && $value !== '' && mb_strlen($value) <= 5000,
                    default => false,
                };

                if (! $valid) {
                    $validator->errors()->add('answers', "The answer for {$question['prompt']} is invalid.");
                }
            }
        }];
    }
}

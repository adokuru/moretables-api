<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\RewardProgramPeriodType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRewardProgramRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'period_type' => ['sometimes', Rule::in([RewardProgramPeriodType::Lifetime->value])],
            'period_value' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'resets_points' => ['sometimes', 'boolean'],
            'tier_locked_until_period_end' => ['sometimes', 'boolean'],
            'levels' => ['sometimes', 'array', 'min:1'],
            'levels.*.name' => ['required_with:levels', 'string', 'max:100'],
            'levels.*.start_points' => ['required_with:levels', 'integer', 'min:0'],
            'levels.*.end_points' => ['nullable', 'integer', 'min:0'],
            'levels.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->filled('period_value')) {
                    $validator->errors()->add('period_value', 'Lifetime reward programs do not use a period value.');
                }

                if ((bool) $this->input('resets_points')) {
                    $validator->errors()->add('resets_points', 'Lifetime reward programs do not reset points.');
                }

                if ((bool) $this->input('tier_locked_until_period_end')) {
                    $validator->errors()->add('tier_locked_until_period_end', 'Lifetime reward programs do not use tier locks by period end.');
                }

                $levels = $this->input('levels');

                if (! is_array($levels)) {
                    return;
                }

                $sortedLevels = collect($levels)
                    ->sortBy('start_points')
                    ->values();

                if ((int) ($sortedLevels->first()['start_points'] ?? -1) !== 0) {
                    $validator->errors()->add('levels', 'The first reward level must start at 0 points.');
                }

                $sortedLevels->each(function (array $level, int $index) use ($sortedLevels, $validator): void {
                    $startPoints = (int) $level['start_points'];
                    $endPoints = $level['end_points'] !== null ? (int) $level['end_points'] : null;

                    if ($endPoints !== null && $endPoints < $startPoints) {
                        $validator->errors()->add("levels.$index.end_points", 'The end points must be greater than or equal to the start points.');
                    }

                    $nextLevel = $sortedLevels->get($index + 1);

                    if (! $nextLevel) {
                        return;
                    }

                    if ($endPoints === null) {
                        $validator->errors()->add("levels.$index.end_points", 'Only the final reward level can leave end points empty.');

                        return;
                    }

                    if ((int) $nextLevel['start_points'] !== $endPoints + 1) {
                        $validator->errors()->add('levels', 'Reward levels must be contiguous with no gaps or overlaps.');
                    }
                });

                if ($sortedLevels->count() > 1 && $sortedLevels->slice(0, -1)->contains(fn (array $level): bool => $level['end_points'] === null)) {
                    $validator->errors()->add('levels', 'Only the highest reward level can have no end points.');
                }
            },
        ];
    }
}

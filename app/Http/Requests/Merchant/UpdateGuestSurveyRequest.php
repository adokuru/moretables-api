<?php

namespace App\Http\Requests\Merchant;

class UpdateGuestSurveyRequest extends StoreGuestSurveyRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['title'][0] = 'sometimes';
        $rules['template_key'] = ['prohibited'];

        return $rules;
    }
}

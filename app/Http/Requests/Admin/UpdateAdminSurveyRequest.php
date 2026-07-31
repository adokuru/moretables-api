<?php

namespace App\Http\Requests\Admin;

class UpdateAdminSurveyRequest extends StoreAdminSurveyRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['scope'][0] = 'sometimes';
        $rules['title'][0] = 'sometimes';

        return $rules;
    }
}

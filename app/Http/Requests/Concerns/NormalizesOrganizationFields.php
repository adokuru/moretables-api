<?php

namespace App\Http\Requests\Concerns;

trait NormalizesOrganizationFields
{
    protected function prepareOrganizationFrontendPayload(): void
    {
        $merged = [];

        $aliases = [
            'business_name' => 'name',
            'business_phone' => 'business_phone',
            'business_email' => 'business_email',
            'business_website' => 'website',
            'business_city' => 'city',
            'business_state' => 'state',
            'business_country' => 'country',
        ];

        foreach ($aliases as $source => $target) {
            if ($this->has($source) && ! $this->has($target)) {
                $merged[$target] = $this->input($source);
            }
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}

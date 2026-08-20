<?php

namespace App\Support;

class MenuDocumentRules
{
    /**
     * Shared rules for menu PDF / Excel uploads.
     *
     * Use extension checks instead of strict `mimetypes:application/pdf`.
     * Browsers (especially on Windows) often report valid PDFs as
     * `application/octet-stream`, which causes intermittent 422s.
     *
     * @return list<string>
     */
    public static function rules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'extensions:pdf,xls,xlsx',
            'max:20480',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $attribute = 'menu_document'): array
    {
        return [
            "{$attribute}.extensions" => 'The menu file must be a PDF or Excel file (.pdf, .xls, .xlsx).',
            "{$attribute}.mimes" => 'The menu file must be a PDF or Excel file (.pdf, .xls, .xlsx).',
            "{$attribute}.max" => 'The menu file may not be greater than 20MB.',
        ];
    }
}

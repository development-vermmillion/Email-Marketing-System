<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'default_subject' => ['nullable', 'string', 'max:255'],
            'html_content' => ['required', 'string', 'max:500000'],
        ];
    }
}

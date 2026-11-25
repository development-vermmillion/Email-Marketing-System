<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TestEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_email' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html_content' => ['nullable', 'string', 'max:500000'],
            'smtp_setting_id' => ['nullable', 'integer', 'exists:smtp_settings,id'],
        ];
    }
}

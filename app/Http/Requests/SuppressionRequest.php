<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuppressionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emails' => ['required', 'string', 'max:100000'], // one or many, separated by newline / comma / semicolon
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SystemSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'sender_address' => ['nullable', 'string', 'max:500'],
            'track_opens' => ['nullable', 'boolean'],
            'track_clicks' => ['nullable', 'boolean'],
            'public_unsubscribe_notice' => ['nullable', 'string', 'max:500'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmtpSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', Rule::in(\App\Models\SmtpSetting::PROVIDERS)],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:255'],
            // On edit a blank password means "keep the stored one" (the stored value is never sent to the browser).
            'password' => ['nullable', 'string', 'max:500'],
            'from_name' => ['required', 'string', 'max:255'],
            'from_email' => ['required', 'email:rfc', 'max:255'],
            'reply_to_email' => ['nullable', 'email:rfc', 'max:255'],
            'rate_per_second' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'daily_limit' => ['nullable', 'integer', 'min:1'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['rate_per_second', 'rate_per_minute', 'daily_limit', 'reply_to_email'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }
}

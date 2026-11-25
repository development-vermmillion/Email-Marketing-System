<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
            'from_email' => ['required', 'email:rfc', 'max:255'],
            'reply_to_email' => ['nullable', 'email:rfc', 'max:255'],
            'email_template_id' => ['required', 'integer', 'exists:email_templates,id'],
            'smtp_setting_id' => ['nullable', 'integer', 'exists:smtp_settings,id'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:audience_groups,id'],
            'scheduled_at' => ['nullable', 'date'],
            'rate_per_second' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'batch_size' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'retry_delay' => ['nullable', 'integer', 'min:30', 'max:86400'],
            'daily_limit' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'in:draft,schedule,send'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Empty form inputs must mean "use the default", not zero.
        foreach (['rate_per_second', 'rate_per_minute', 'batch_size', 'max_attempts', 'retry_delay', 'daily_limit', 'smtp_setting_id', 'reply_to_email', 'scheduled_at'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }
}

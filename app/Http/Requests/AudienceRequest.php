<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        $id = $this->route('audience')?->id;

        return [
            'name' => ['nullable', 'string', 'max:255'],
            // Soft-deleted contacts do not block re-adding the same address (the service restores them).
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('audiences', 'email')->ignore($id)->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'subscription_status' => ['sometimes', Rule::in(['subscribed', 'unsubscribed'])],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:audience_groups,id'],
        ];
    }

    public function messages(): array
    {
        return ['email.unique' => 'A contact with this email address already exists.'];
    }
}

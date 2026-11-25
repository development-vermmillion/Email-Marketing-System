<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AudienceGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('audience_groups', 'name')->ignore($this->route('group')?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}

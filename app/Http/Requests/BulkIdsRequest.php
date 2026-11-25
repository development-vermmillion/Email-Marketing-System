<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkIdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:audience_groups,id'],
            'mode' => ['nullable', 'in:add,remove'],
        ];
    }

    public function messages(): array
    {
        return ['ids.required' => 'Select at least one contact first.', 'ids.min' => 'Select at least one contact first.'];
    }
}

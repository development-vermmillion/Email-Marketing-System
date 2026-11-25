<?php

namespace App\Http\Requests;

use App\Enums\RecipientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(RecipientStatus::values())],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'email' => ['nullable', 'string', 'max:255'],
        ];
    }
}

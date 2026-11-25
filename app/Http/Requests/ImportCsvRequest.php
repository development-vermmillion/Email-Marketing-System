<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Extension AND sniffed MIME type are both checked; the stored name is a server-generated UUID.
            'file' => ['required', 'file', 'mimes:csv,txt', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel', 'max:'.config('mailing.csv.max_kb')],
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['integer', 'exists:audience_groups,id'],
        ];
    }

    public function messages(): array
    {
        return ['file.max' => 'The CSV file may not be larger than '.round(config('mailing.csv.max_kb') / 1024).' MB.'];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RatingFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('user_id') && $this->input('user_id') !== null && $this->input('user_id') !== '') {
            $this->merge([
                'user_id' => (int) $this->input('user_id'),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'user_type' => ['required', 'string', Rule::in(['passenger', 'driver'])],
            'user_id' => ['nullable', 'integer', 'exists:users,user_id'],
            'name' => ['nullable', 'string'],
            'number' => ['nullable', 'string'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ];
    }
}

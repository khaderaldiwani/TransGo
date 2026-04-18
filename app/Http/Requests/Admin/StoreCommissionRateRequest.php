<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'change_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

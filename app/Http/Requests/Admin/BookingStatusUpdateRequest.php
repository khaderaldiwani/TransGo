<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingStatusUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['accepted', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:status,rejected'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.in' => 'يجب أن تكون الحالة إما مقبول أو مرفوض.',
            'reason.required_if' => 'يجب تقديم سبب الرفض عند اختيار حالة مرفوض.',
        ];
    }
}

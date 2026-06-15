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

    public function validationData(): array
    {
        $data = parent::validationData();

        $data = array_merge($data, [
            'user_type' => $this->header('user_type') ?? $this->header('x-user-type') ?? $data['user_type'] ?? null,
            'user_id' => $this->header('user_id') ?? $this->header('x-user-id') ?? $data['user_id'] ?? null,
            'name' => $this->header('name') ?? $this->header('x-name') ?? $data['name'] ?? null,
            'number' => $this->header('number') ?? $this->header('x-number') ?? $data['number'] ?? null,
            'from_date' => $this->header('from_date') ?? $this->header('x-from-date') ?? $data['from_date'] ?? null,
            'to_date' => $this->header('to_date') ?? $this->header('x-to-date') ?? $data['to_date'] ?? null,
        ]);

        if (isset($data['user_id']) && $data['user_id'] !== null && $data['user_id'] !== '') {
            $data['user_id'] = (int) $data['user_id'];
        }

        return $data;
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



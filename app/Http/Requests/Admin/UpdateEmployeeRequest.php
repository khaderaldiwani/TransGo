<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = (int) $this->route('id');

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($employeeId, 'user_id')],
            'role' => ['sometimes', 'required', Rule::in([Role::ROLE_ADMIN, Role::ROLE_EMPLOYEE])],
        ];
    }
}

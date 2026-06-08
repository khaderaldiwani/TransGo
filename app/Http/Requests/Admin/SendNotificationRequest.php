<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'target_user_id' => ['nullable', 'integer', 'exists:users,user_id', 'required_without:target_role'],
            'target_role' => ['nullable', 'string', Rule::in([Role::ROLE_PASSENGER, Role::ROLE_DRIVER]), 'required_without:target_user_id'],
            'target_governorate_id' => ['nullable', 'integer', 'exists:governorates,governorate_id'],
            'notification_type' => ['nullable', 'string', 'max:100'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer'],
        ];
    }
}

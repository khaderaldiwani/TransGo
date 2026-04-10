<?php

namespace App\Http\Requests\Driver;

use App\Http\Resources\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'departure_time' => ['required', 'date', 'after_or_equal:now'],
            'total_seats' => ['required', 'integer', 'min:1'],
            'allow_shared' => ['required', 'boolean'],
            'allow_private' => ['required', 'boolean'],
            'shared_price' => ['nullable', 'numeric', 'min:0.01'],
            'private_price' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'points' => ['required', 'array', 'min:2'],
            'points.*.point_type' => ['required', 'string', 'in:start,stop,end'],
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'points.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateTripTypeSelection($validator);
            $this->validateConditionalPrices($validator);
            $this->validateSeatCapacity($validator);
            $this->validatePoints($validator);
        });
    }

    public function messages(): array
    {
        return [
            'departure_time.required' => 'وقت الانطلاق مطلوب.',
            'departure_time.date' => 'وقت الانطلاق يجب أن يكون تاريخاً صالحاً.',
            'departure_time.after_or_equal' => 'لا يمكن إنشاء رحلة بوقت انطلاق في الماضي.',
            'total_seats.required' => 'عدد المقاعد مطلوب.',
            'total_seats.integer' => 'عدد المقاعد يجب أن يكون رقماً صحيحاً.',
            'total_seats.min' => 'عدد المقاعد يجب أن يكون على الأقل 1.',
            'allow_shared.required' => 'يجب تحديد ما إذا كانت الرحلة مشتركة.',
            'allow_shared.boolean' => 'قيمة الرحلة المشتركة غير صحيحة.',
            'allow_private.required' => 'يجب تحديد ما إذا كانت الرحلة خاصة.',
            'allow_private.boolean' => 'قيمة الرحلة الخاصة غير صحيحة.',
            'shared_price.numeric' => 'سعر المقعد المشترك يجب أن يكون رقماً.',
            'shared_price.min' => 'سعر المقعد المشترك يجب أن يكون أكبر من صفر.',
            'private_price.numeric' => 'سعر الرحلة الخاصة يجب أن يكون رقماً.',
            'private_price.min' => 'سعر الرحلة الخاصة يجب أن يكون أكبر من صفر.',
            'notes.string' => 'ملاحظات الرحلة يجب أن تكون نصاً.',
            'notes.max' => 'ملاحظات الرحلة طويلة جداً.',
            'points.required' => 'نقاط الرحلة مطلوبة.',
            'points.array' => 'نقاط الرحلة يجب أن تكون على شكل قائمة.',
            'points.min' => 'يجب إدخال نقطتين على الأقل للرحلة.',
            'points.*.point_type.required' => 'نوع نقطة الرحلة مطلوب.',
            'points.*.point_type.in' => 'نوع نقطة الرحلة يجب أن يكون start أو stop أو end.',
            'points.*.latitude.required' => 'خط العرض مطلوب لكل نقطة.',
            'points.*.latitude.numeric' => 'خط العرض يجب أن يكون رقماً صالحاً.',
            'points.*.latitude.between' => 'خط العرض يجب أن يكون بين -90 و 90.',
            'points.*.longitude.required' => 'خط الطول مطلوب لكل نقطة.',
            'points.*.longitude.numeric' => 'خط الطول يجب أن يكون رقماً صالحاً.',
            'points.*.longitude.between' => 'خط الطول يجب أن يكون بين -180 و 180.',
            'points.*.note.string' => 'ملاحظة النقطة يجب أن تكون نصاً.',
            'points.*.note.max' => 'ملاحظة النقطة طويلة جداً.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::validation('Validation failed.', $validator->errors(), 422)
        );
    }

    private function validateTripTypeSelection(Validator $validator): void
    {
        if (! $this->boolean('allow_shared') && ! $this->boolean('allow_private')) {
            $validator->errors()->add(
                'allow_shared',
                'يجب اختيار الرحلة المشتركة أو الرحلة الخاصة أو كلاهما.'
            );
        }
    }

    private function validateConditionalPrices(Validator $validator): void
    {
        if ($this->boolean('allow_shared') && ! $this->filled('shared_price')) {
            $validator->errors()->add(
                'shared_price',
                'سعر المقعد المشترك مطلوب عند تفعيل خيار الرحلة المشتركة.'
            );
        }

        if ($this->boolean('allow_private') && ! $this->filled('private_price')) {
            $validator->errors()->add(
                'private_price',
                'سعر الرحلة الخاصة مطلوب عند تفعيل خيار الرحلة الخاصة.'
            );
        }
    }

    private function validateSeatCapacity(Validator $validator): void
    {
        $seatCapacity = data_get($this->user(), 'driverProfile.vehicles.0.seat_capacity');

        if ($seatCapacity === null) {
            $validator->errors()->add(
                'total_seats',
                'لا يمكن التحقق من عدد المقاعد لأن سعة السيارة المسجلة غير متوفرة.'
            );

            return;
        }

        if ((int) $this->input('total_seats') > (int) $seatCapacity) {
            $validator->errors()->add(
                'total_seats',
                'عدد المقاعد المطلوبة يتجاوز سعة السيارة المسجلة في النظام.'
            );
        }
    }

    private function validatePoints(Validator $validator): void
    {
        $points = $this->input('points', []);

        if (! is_array($points) || $points === []) {
            return;
        }

        $startPoints = array_values(array_filter($points, fn ($point) => ($point['point_type'] ?? null) === 'start'));
        $endPoints = array_values(array_filter($points, fn ($point) => ($point['point_type'] ?? null) === 'end'));

        if (count($startPoints) !== 1) {
            $validator->errors()->add('points', 'يجب أن تحتوي الرحلة على نقطة بداية واحدة فقط.');
        }

        if (count($endPoints) !== 1) {
            $validator->errors()->add('points', 'يجب أن تحتوي الرحلة على نقطة نهاية واحدة فقط.');
        }

        if (($points[0]['point_type'] ?? null) !== 'start') {
            $validator->errors()->add('points', 'أول نقطة في الرحلة يجب أن تكون نقطة البداية.');
        }

        if (($points[array_key_last($points)]['point_type'] ?? null) !== 'end') {
            $validator->errors()->add('points', 'آخر نقطة في الرحلة يجب أن تكون نقطة النهاية.');
        }
    }
}

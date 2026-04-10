<?php

namespace App\Http\Requests\Driver;

use App\Http\Resources\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PreviewTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'total_seats' => ['required', 'integer', 'min:1'],
            'allow_shared' => ['required', 'boolean'],
            'allow_private' => ['required', 'boolean'],
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
            $this->validateSeatCapacity($validator);
            $this->validatePoints($validator);
        });
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

<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'name' => data_get($this->resource, 'name'),
            'photo' => data_get($this->resource, 'photo'),
            'phone_number' => data_get($this->resource, 'phone_number'),
            'email' => data_get($this->resource, 'email'),
            'car_plate_number' => data_get($this->resource, 'car_plate_number'),
            'car_type' => data_get($this->resource, 'car_type'),
            'car_photos' => data_get($this->resource, 'car_photos', []),
            'overall_rating' => data_get($this->resource, 'overall_rating'),
        ];
    }
}

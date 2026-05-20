<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PassengerProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'photo' => data_get($this->resource, 'photo'),
            'name' => data_get($this->resource, 'name'),
            'email' => $this->when(isset($this->resource['email']) && $this->resource['email'] !== null, data_get($this->resource, 'email')),
            'phone_number' => $this->when(isset($this->resource['phone_number']) && $this->resource['phone_number'] !== null, data_get($this->resource, 'phone_number')),
            'cancelled_reservations_count' => (int) data_get($this->resource, 'cancelled_reservations_count', 0),
            'completed_reservations_count' => (int) data_get($this->resource, 'completed_reservations_count', 0),
            'rating' => data_get($this->resource, 'rating'),
        ];
    }
}

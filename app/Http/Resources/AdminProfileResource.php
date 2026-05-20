<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'photo' => data_get($this->resource, 'photo'),
            'name' => data_get($this->resource, 'name'),
            'email' => data_get($this->resource, 'email'),
            'phone_number' => data_get($this->resource, 'phone_number'),
        ];
    }
}

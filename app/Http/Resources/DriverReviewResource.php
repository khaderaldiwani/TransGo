<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DriverReviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'stars' => (int) $this->rating,
            'comment' => $this->comment,
            'review_date' => $this->created_at?->toIso8601String(),
            'passenger_name' => $this->passenger?->full_name,
            'passenger_photo' => $this->passenger?->profile_photo,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\RatingRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TripRatingService
{
    public function __construct(private readonly RatingRepository $ratingRepository)
    {
    }

    public function rateTrip(int $tripId, User $passenger, array $payload): array
    {
        $booking = $this->ratingRepository->findCompletedPassengerBookingForTrip($tripId, $passenger->user_id);

        if (! $booking) {
            throw new RuntimeException('Completed trip booking not found for this passenger.', 404);
        }

        if ($booking->review !== null) {
            throw new RuntimeException('This trip has already been rated by the passenger.', 422);
        }

        if (! $booking->trip?->driver_id) {
            throw new RuntimeException('Trip driver was not found.', 422);
        }

        $review = DB::transaction(function () use ($booking, $payload) {
            return $this->ratingRepository->createDriverRating(
                $booking,
                (int) $payload['stars'],
                isset($payload['comment']) ? trim((string) $payload['comment']) : null
            );
        });

        $this->ratingRepository->syncDriverStoredRating($booking->trip->driver_id);

        return [
            'rating_id' => $review->review_id,
            'trip_id' => $tripId,
            'booking_id' => $booking->booking_id,
            'driver_id' => $review->driver_id,
            'stars' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    public function getDriverRatingSummary(User $driver): array
    {
        $analytics = $this->ratingRepository->findVisibleDriverRating($driver->user_id);

        return [
            'average_rating' => $analytics['average'],
            'total_ratings' => $analytics['total'],
            'breakdown' => $analytics['breakdown'],
            'comments' => $analytics['comments']->values(),
            'recent_comments' => $analytics['comments']->take(10)->values(),
        ];
    }

    public function getAdminRatings(array $filters): array
    {
        return $this->ratingRepository->getAdminRatingAnalytics($filters);
    }

    public function getAdminRatingsList(array $filters): array
    {
        $analytics = $this->ratingRepository->getAdminRatingAnalytics($filters);

        return [
            'filters' => $filters,
            'summary' => [
                'average_rating' => $analytics['summary']['average_rating'] ?? 0,
                'total_ratings' => $analytics['summary']['total_ratings'] ?? 0,
                'breakdown' => $analytics['summary']['breakdown'] ?? [
                    '1' => 0,
                    '2' => 0,
                    '3' => 0,
                    '4' => 0,
                    '5' => 0,
                ],
            ],
            'items' => $this->ratingRepository->getAdminRatingList($filters),
        ];
    }

    public function toggleRatingVisibility(int $ratingId, User $actor): array
    {
        $review = $this->ratingRepository->toggleRatingVisibility($ratingId, $actor);
        $this->ratingRepository->syncDriverStoredRating($review->driver_id);

        return [
            'rating_id' => $review->review_id,
            'is_visible' => $review->is_visible,
            'action' => $review->is_visible ? 'unhidden' : 'hidden',
            'hidden_at' => $review->hidden_at?->toIso8601String(),
            'hidden_by' => [
                'user_id' => $review->hiddenBy?->user_id,
                'full_name' => $review->hiddenBy?->full_name,
            ],
        ];
    }

    public function getLowRatedDrivers(): array
    {
        $items = $this->ratingRepository->getLowRatedDrivers();

        return [
            'items' => $items,
            'total' => $items->count(),
        ];
    }
}

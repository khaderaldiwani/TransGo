<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

try {
    $user = App\Models\User::where('phone', '0931000001')->first();
    if (! $user) {
        echo "User not found\n";
        exit(1);
    }
    $svc = $app->make(App\Services\BookingService::class);
    $data = [
        'trip_id' => 1,
        'booking_type' => 'shared',
        'seats_reserved' => 2,
        'payment_method' => 'cash',
        'pickup_point' => ['trip_point_id' => 1],
    ];
    $booking = $svc->createBooking($data, $user);
    echo "OK\n";
    print_r($booking->toArray());
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

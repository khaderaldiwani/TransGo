<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiResponse;
use App\Services\UserNotificationFeedService;
use Illuminate\Http\Request;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(private readonly UserNotificationFeedService $notifications)
    {
    }

    public function index(Request $request)
    {
        try {
            $filters = $request->validate([
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            return ApiResponse::success(
                'تم جلب إشعارات السائق بنجاح.',
                200,
                $this->notifications->listForUser($request->user(), $filters)
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء جلب الإشعارات.', 500);
        }
    }

    public function readAll(Request $request)
    {
        try {
            return ApiResponse::success(
                'تم تعليم جميع إشعارات السائق كمقروءة.',
                200,
                $this->notifications->markAllAsRead($request->user())
            );
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء تحديث الإشعارات.', 500);
        }
    }
}

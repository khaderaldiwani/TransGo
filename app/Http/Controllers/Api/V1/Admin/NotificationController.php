<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendNotificationRequest;
use App\Http\Resources\ApiResponse;
use App\Services\NotificationDispatchService;
use RuntimeException;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationDispatchService $notifications)
    {
    }

    public function store(SendNotificationRequest $request)
    {
        try {
            return ApiResponse::success(
                'تم إرسال الإشعار بنجاح.',
                201,
                $this->notifications->sendAdminAnnouncement($request->user(), $request->validated())
            );
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (Throwable $e) {
            return ApiResponse::error('حدث خطأ غير متوقع أثناء إرسال الإشعار.', 500);
        }
    }
}

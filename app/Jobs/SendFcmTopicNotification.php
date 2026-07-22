<?php

namespace App\Jobs;

use App\Services\FcmV1Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFcmTopicNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $topic,
        private readonly string $title,
        private readonly string $body,
        private readonly array $data = []
    ) {
    }

    public function handle(FcmV1Service $fcm): void
    {
        $fcm->sendToTopic($this->topic, $this->title, $this->body, $this->data);
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** In-app staff notification shown in the top-bar bell. */
class SystemAlert extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $category,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $link = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
        ];
    }
}

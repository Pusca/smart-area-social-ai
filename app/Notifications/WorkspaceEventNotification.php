<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class WorkspaceEventNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly array $options = []
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $level = Str::lower(trim((string) ($this->options['level'] ?? 'info')));
        if (!in_array($level, ['info', 'success', 'warning', 'error'], true)) {
            $level = 'info';
        }

        return [
            'title' => Str::limit(trim($this->title), 120, ''),
            'body' => Str::limit(trim($this->body), 320, ''),
            'level' => $level,
            'icon' => trim((string) ($this->options['icon'] ?? 'activity')),
            'action_url' => trim((string) ($this->options['action_url'] ?? '')) ?: null,
            'action_label' => trim((string) ($this->options['action_label'] ?? '')) ?: null,
            'context_type' => trim((string) ($this->options['context_type'] ?? '')) ?: null,
            'context_id' => isset($this->options['context_id']) ? (string) $this->options['context_id'] : null,
            'meta' => is_array($this->options['meta'] ?? null) ? $this->options['meta'] : [],
            'emitted_at' => now()->toDateTimeString(),
        ];
    }
}

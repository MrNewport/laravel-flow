<?php

namespace MrNewport\LaravelFlow\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;
use MrNewport\LaravelFlow\Support\NotificationText;

class FlowBaseNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FlowInstanceStep $step,
        public string $action
    ) {}

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject(__('laravel-flow::messages.subject', [
                'step' => NotificationText::singleLine($this->step->step_id),
                'action' => NotificationText::singleLine($this->action),
            ]))
            ->markdown('laravel-flow::notifications.flow_base', [
                'step' => $this->step,
                'action' => $this->action,
            ]);
    }
}

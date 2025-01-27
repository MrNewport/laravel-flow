<?php

namespace MrNewport\LaravelFlow\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use MrNewport\LaravelFlow\Models\FlowInstanceStep;

class FlowBaseNotification extends Notification
{
    use Queueable;

    public function __construct(
        public FlowInstanceStep $step,
        public string $action
    ){}

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject("Flow Step: {$this->step->step_id} Action: {$this->action}")
            ->markdown('laravel-flow::notifications.flow_base', [
                'step'   => $this->step,
                'action' => $this->action
            ]);
    }
}

<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Tey\Kit\Mail\FluentMailMessage;

final class Welcome extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): FluentMailMessage
    {
        return (new FluentMailMessage)->subject('Welcome')->line('Your account is ready.');
    }
}

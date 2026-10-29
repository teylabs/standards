<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Tey\Kit\Mail\FluentMailMessage;

final class WelcomeMail extends Mailable
{
    public function build(): FluentMailMessage
    {
        return new FluentMailMessage;
    }
}

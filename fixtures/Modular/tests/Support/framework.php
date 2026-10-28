<?php

// Minimal framework doubles for the fixture app when laravel/framework is not
// installed. The kit is never doubled: the package suite requires the real
// tey/kit, so generated kit classes are proven against its signatures.

namespace Illuminate\Foundation\Http {
    if (! class_exists(FormRequest::class)) {
        class FormRequest
        {
            /** @return array<string, mixed> */
            public function validated(): array
            {
                return [];
            }
        }
    }
}

namespace Illuminate\Notifications {
    if (! class_exists(Notification::class)) {
        class Notification {}
    }
}

namespace Illuminate\Notifications\Messages {
    if (! class_exists(MailMessage::class)) {
        class MailMessage
        {
            public function line(string $line): static
            {
                return $this;
            }
        }
    }
}

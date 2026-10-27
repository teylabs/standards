<?php

// Minimal framework and kit doubles so the fixture app's classes autoload in
// the package suite. A real app has the real classes.

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

namespace Tey\Kit\Features\Core {
    if (! class_exists(Principal::class)) {
        final class Principal {}
    }
}

namespace Tey\Kit\Features\Mcp {
    if (! class_exists(Capability::class)) {
        #[\Attribute(\Attribute::TARGET_CLASS)]
        final class Capability
        {
            public function __construct(public string $group) {}
        }

        abstract class CapabilityTool
        {
            /**
             * @param  array<string, mixed>  $arguments
             * @return array<string, mixed>
             */
            abstract protected function call(\Tey\Kit\Features\Core\Principal $principal, int $workspace, array $arguments): array;
        }
    }
}

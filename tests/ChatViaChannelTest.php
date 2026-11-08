<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** Rule notifications.chat-via-channel on a scratch app; tenantConfiguration() comes from TenantAwareTest, as in ScopedExistsTest. */
function chatFindings(array $files): array
{
    $base = tenantApp($files);
    try {
        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'], (new Conformance(tenantConfiguration($base)))->violations('notifications.chat-via-channel'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('notifications.chat-via-channel flags a direct post to chat.postMessage, a /api/chat. endpoint or a hooks.slack.com webhook', function () {
    expect(chatFindings(['SlackChatProvider.php' => <<<'PHP'
        final class SlackChatProvider
        {
            public function post(string $token, string $channel, string $text): void
            {
                Http::slack($token)->post('/api/chat.postMessage', ['channel' => $channel, 'text' => $text]);
                Http::post('https://hooks.slack.com/services/'.$this->path, ['text' => $text]);
                Http::withToken($token)->post(self::BASE.'/api/chat.meMessage', []);
                Http::post(url: '/api/chat.postMessage', data: []);
            }
        }
        PHP]))->toBe([
        "src/SlackChatProvider.php:9 a chat message posted straight to the provider's API (/api/chat.postMessage) instead of through a Notification channel",
        "src/SlackChatProvider.php:10 a chat message posted straight to the provider's API (https://hooks.slack.com/services/) instead of through a Notification channel",
        "src/SlackChatProvider.php:11 a chat message posted straight to the provider's API (/api/chat.meMessage) instead of through a Notification channel",
        "src/SlackChatProvider.php:12 a chat message posted straight to the provider's API (/api/chat.postMessage) instead of through a Notification channel",
    ])->and(Gate::ADVISORY)->toContain('notifications.chat-via-channel');
});

test('notifications.chat-via-channel passes a call inside a Notification channel class and a class marked as the digest exception', function () {
    expect(chatFindings([
        'TeamsChannel.php' => <<<'PHP'
            use Illuminate\Notifications\Notification;

            final class TeamsChannel
            {
                public function send(object $notifiable, Notification $notification): void
                {
                    Http::post('https://hooks.slack.com/services/x', ['text' => 'x']);
                }
            }
            PHP,
        'DigestClient.php' => <<<'PHP'
            /**
             * Edits the morning digest in place.
             *
             * @chat-digest chat.update on the stored ts keeps one message per day
             */
            final class DigestClient
            {
                public function post(string $token): void
                {
                    Http::slack($token)->post('/api/chat.postMessage', ['channel' => 'c']);
                    Http::slack($token)->post('/api/chat.update', ['ts' => $this->ts]);
                }
            }
            PHP,
    ]))->toBe([]);
});

test('notifications.chat-via-channel takes the digest tag only as a tag with a reason', function () {
    $client = fn (string $tag): array => ['Client.php' => "/**\n * {$tag}\n */\nfinal class Client\n{\n    public function post(): void\n    {\n        Http::post('/api/chat.update', []);\n    }\n}\n"];

    expect(chatFindings($client('@chat-digest')))->toHaveCount(1)
        ->and(chatFindings($client('@chat-digestible edits in place')))->toHaveCount(1)
        ->and(chatFindings($client('@chat-digest edits the daily digest in place')))->toBe([]);
});

test('notifications.chat-via-channel ignores a mention in a comment or a plain string', function () {
    expect(chatFindings(['Notes.php' => <<<'PHP'
        final class Notes
        {
            // Slack's chat.postMessage has no idempotency key; hooks.slack.com webhooks neither.
            public const ERROR = 'chat.postMessage failed';

            public function log(): void
            {
                $message = 'Posted via /api/chat.postMessage';
                $class = Http::class;
            }
        }
        PHP]))->toBe([]);
});

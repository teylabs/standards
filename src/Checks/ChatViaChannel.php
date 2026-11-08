<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `notifications.chat-via-channel`: a message to a chat app (Slack now; Teams and Discord to follow) is a Notification sent through its channel
 * (`toSlack()`, laravel/slack-notification-channel), not a direct call to the provider's API. A string passed as a call argument that names
 * `chat.postMessage`, a `/api/chat.` endpoint or a `hooks.slack.com` webhook is flagged, unless it sits in a Notification channel class (one with a
 * `send($notifiable, Notification $notification)` method) or in a class whose docblock carries `@chat-digest <reason>`: the one exception, a digest
 * edited in place with `chat.update` on a stored `ts`, which stays on a provider client.
 *
 * Lexical: comments don't count, and neither does a string that is not a call argument (a log message, a constant). Advisory until an app lists the
 * rule in Configuration::$optionalRules.
 */
final readonly class ChatViaChannel
{
    public const RULE = 'notifications.chat-via-channel';

    public const MARKER = '@chat-digest';

    private const ENDPOINT = '~chat\.postMessage|/api/chat\.|hooks\.slack\.com~';

    private const HINT = 'Send it as a Notification: toSlack() through laravel/slack-notification-channel, the tenant\'s bot token from routeNotificationForSlack($notification) (failing closed when none is connected), and the channel chosen in via(). Only a digest edited in place with chat.update stays on a provider client: mark its class with a `@chat-digest <reason>` docblock tag.';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string}> */
    public function violations(): array
    {
        $violations = [];
        foreach ($this->source->files($this->config->sourceRoots) as $path => $text) {
            if (preg_match(self::ENDPOINT, $text) !== 1) {
                continue;
            }
            $tokens = array_values(array_filter(token_get_all($text), fn (mixed $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT], true)));
            $class = null;
            $classDepth = null;
            $depth = 0;
            $doc = '';
            foreach ($tokens as $i => $token) {
                if (is_array($token) && $token[0] === T_DOC_COMMENT) {
                    $doc = $token[1];

                    continue;
                }
                if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    if ($classDepth !== null && $depth < $classDepth) {
                        [$class, $classDepth] = [null, null];
                    }
                } elseif (is_array($token) && $token[0] === T_CLASS && $class === null && ! (is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_DOUBLE_COLON)) {
                    $class = ['exempt' => str_contains($doc, self::MARKER) || $this->channel($tokens, $i)];
                    $classDepth = $depth + 1;
                } elseif (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                    && preg_match(self::ENDPOINT, $token[1]) === 1 && ! ($class['exempt'] ?? false) && $this->argument($tokens, $i)) {
                    $violations[] = ['target' => $path, 'check' => 'a chat message posted straight to the provider\'s API ('.trim($token[1], '\'"').') instead of through a Notification channel', 'line' => $token[2], 'hint' => self::HINT];
                }
                if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                    $doc = in_array($token, [';', '{', '}'], true) ? '' : $doc;
                }
            }
        }

        return $violations;
    }

    /** Whether the string at $i is a call argument: before it, past any concatenation, is the call's `(` or a `,`. */
    private function argument(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if ($token === '.' || $token === '"' || (is_array($token) && in_array($token[0], [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true))) {
                continue;
            }

            return $token === '(' || $token === ',';
        }

        return false;
    }

    /** Whether the class starting at token $i declares a Notification channel's `send($notifiable, Notification $notification)`. */
    private function channel(array $tokens, int $i): bool
    {
        $depth = 0;
        for ($j = $i, $count = count($tokens); $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token === '{') {
                $depth++;
            } elseif ($token === '}' && --$depth === 0) {
                return false;
            } elseif (is_array($token) && $token[0] === T_FUNCTION && is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][1] === 'send') {
                $signature = '';
                for ($k = $j + 2; $k < $count && $tokens[$k] !== ')'; $k++) {
                    $signature .= is_array($tokens[$k]) ? $tokens[$k][1].' ' : $tokens[$k];
                }

                return preg_match('~\bNotification\s+\$\w+~', $signature) === 1;
            }
        }

        return false;
    }
}

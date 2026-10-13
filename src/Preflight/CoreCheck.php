<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class CoreCheck implements Check
{
    public const NAMES = ['app.key', 'app.env-debug', 'database.config', 'mail.transport', 'mail.from', 'mail.reply-to', 'queue.connection', 'cache.store', 'app.url', 'logging.channel'];

    private const TRANSPORTS = [
        'resend' => [['resend/resend-php'], ['services.resend.key' => 'RESEND_API_KEY']],
        'postmark' => [['symfony/postmark-mailer', 'symfony/http-client'], ['services.postmark.key' => 'POSTMARK_API_KEY']],
        'mailgun' => [['symfony/mailgun-mailer', 'symfony/http-client'], ['services.mailgun.secret' => 'MAILGUN_SECRET', 'services.mailgun.domain' => 'MAILGUN_DOMAIN']],
        'ses' => [['aws/aws-sdk-php'], ['services.ses.key' => 'AWS_ACCESS_KEY_ID', 'services.ses.secret' => 'AWS_SECRET_ACCESS_KEY']],
        'ses-v2' => [['aws/aws-sdk-php'], ['services.ses.key' => 'AWS_ACCESS_KEY_ID', 'services.ses.secret' => 'AWS_SECRET_ACCESS_KEY']],
    ];

    public function __construct(private string $check, private Repository $config) {}

    public function name(): string
    {
        return $this->check;
    }

    public function level(): Level
    {
        return in_array($this->check, ['app.key', 'app.env-debug', 'database.config'], true)
            ? Level::Blocking : Level::Warning;
    }

    public function needsSecrets(): bool
    {
        if ($this->check === 'app.key') {
            return true;
        }

        if ($this->check !== 'mail.transport') {
            return false;
        }

        foreach ($this->mailers((string) $this->config->get('mail.default')) as $mailer) {
            if (isset(self::TRANSPORTS[$mailer['transport'] ?? ''])) {
                return true;
            }
        }

        return false;
    }

    public function run(): Result
    {
        return match ($this->check) {
            'app.key' => $this->result($this->set('app.key'), 'Set APP_KEY (php artisan key:generate).'),
            'app.env-debug' => $this->result(! $this->production() || ! $this->config->get('app.debug'), 'Set APP_DEBUG=false in production.'),
            'database.config' => $this->database(),
            'mail.transport' => $this->mailTransport(),
            'mail.from' => $this->mailFrom(),
            'mail.reply-to' => $this->mailReplyTo(),
            'queue.connection' => $this->result(! $this->production() || $this->config->get('queue.default') !== 'sync', 'Set QUEUE_CONNECTION to a non-sync connection in production.'),
            'cache.store' => $this->result(! $this->production() || $this->config->get('cache.default') !== 'array', 'Set CACHE_STORE to a persistent store in production.'),
            'app.url' => $this->result($this->set('app.url') && (! $this->production() || (filter_var($this->config->get('app.url'), FILTER_VALIDATE_URL) !== false && parse_url((string) $this->config->get('app.url'), PHP_URL_SCHEME) === 'https')), 'Set APP_URL to the public HTTPS URL in production.'),
            'logging.channel' => $this->result($this->set('logging.default') && $this->set('logging.channels.'.$this->config->get('logging.default').'.driver'), 'Set LOG_CHANNEL to a configured logging channel.'),
            default => throw new InvalidArgumentException('Unknown core check: '.$this->check),
        };
    }

    private function database(): Result
    {
        $connection = 'database.connections.'.$this->config->get('database.default');
        $configured = $this->set('database.default') && $this->set($connection.'.driver')
            && ($this->set($connection.'.database') || $this->set($connection.'.url'));

        return $this->result($configured, 'Set DB_CONNECTION and its database or DB_URL configuration.');
    }

    private function mailTransport(): Result
    {
        $mailers = $this->mailers((string) $this->config->get('mail.default'));

        if ($mailers === []) {
            return Result::fail('Set MAIL_MAILER to a configured transport (and configure all composite mailers).');
        }

        foreach ($mailers as $mailer) {
            $transport = $mailer['transport'] ?? '';

            if (isset(self::TRANSPORTS[$transport])) {
                [$packages, $keys] = self::TRANSPORTS[$transport];

                foreach ($packages as $package) {
                    if (! InstalledVersions::isInstalled($package)) {
                        return Result::fail('Install '.$package.' for the configured '.$transport.' mail transport.');
                    }
                }

                foreach ($keys as $key => $env) {
                    $field = substr($key, strrpos($key, '.') + 1);
                    $value = $mailer[$field] ?? $this->config->get($key);

                    if ($transport === 'postmark') {
                        $value = $mailer['token'] ?? $mailer['key'] ?? $this->config->get('services.postmark.token') ?? $this->config->get($key);
                    } elseif ($transport === 'mailgun') {
                        $value = isset($mailer['secret']) ? ($mailer[$field] ?? null) : $this->config->get($key);
                    } elseif (in_array($transport, ['ses', 'ses-v2'], true) && array_key_exists($field, $mailer)) {
                        $value = $mailer[$field];
                    }

                    if (! is_string($value) || trim($value) === '') {
                        return Result::fail('Set '.$env.' ('.$key.' or its mailer override) for the configured '.$transport.' mail transport.');
                    }
                }
            } elseif (! in_array($transport, ['smtp', 'sendmail', 'log', 'array'], true)) {
                return Result::fail('Register an app transport check for '.$transport.' and configure MAIL_MAILER.');
            } elseif ($transport === 'smtp' && empty($mailer['host']) && empty($mailer['url'])) {
                return Result::fail('Set MAIL_HOST or MAIL_URL for the SMTP mail transport.');
            }
        }

        return Result::pass('Configured mail transport packages and keys are present.');
    }

    /** @param list<string> $seen
     * @return list<array<string, mixed>>
     */
    private function mailers(string $name, array $seen = []): array
    {
        $mailer = $this->config->get('mail.mailers.'.$name);

        if (in_array($name, $seen, true) || ! is_array($mailer) || empty($mailer['transport'])) {
            return [];
        }

        if (! in_array($mailer['transport'], ['failover', 'roundrobin'], true)) {
            return [$mailer];
        }

        $mailers = [];

        foreach ($mailer['mailers'] ?? [] as $child) {
            $resolved = $this->mailers($child, [...$seen, $name]);

            if ($resolved === []) {
                return [];
            }

            $mailers = [...$mailers, ...$resolved];
        }

        return $mailers;
    }

    private function mailFrom(): Result
    {
        if (! $this->sendsMail()) {
            return Result::pass('Log and array transports do not require a sender.');
        }

        if (! $this->validMailFrom()) {
            return Result::fail('Set MAIL_FROM_ADDRESS to a valid sender on the app\'s verified domain, outside example.com and its subdomains.');
        }

        $address = strtolower(trim((string) $this->config->get('mail.from.address')));
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');
        $parent = strtolower(trim((string) $this->config->get('mail.shared_parent_domain')));
        $generic = $parent !== '' && $domain === $parent && in_array($local, $this->config->get('preflight.mail_generic_local_parts', []), true);

        return $generic
            ? Result::warn('Use a sender that names the brand on mail.shared_parent_domain (<brand>@<shared parent domain>).')
            : Result::pass('Configured sender is valid and app-specific.');
    }

    private function mailReplyTo(): Result
    {
        if (! $this->sendsMail()) {
            return Result::pass('Log and array transports do not require Reply-To.');
        }

        $from = strtolower(trim((string) $this->config->get('mail.from.address')));
        $replyTo = strtolower(trim((string) $this->config->get('mail.reply_to.address')));

        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            return Result::fail('Set MAIL_REPLY_TO_ADDRESS (mail.reply_to.address) and MAIL_FROM_ADDRESS to valid addresses on the app\'s own domain.');
        }

        return $this->mailDomain($from) === $this->mailDomain($replyTo)
            ? Result::pass('Configured Reply-To is valid and on the sender\'s domain (last two labels).')
            : Result::fail('Set MAIL_REPLY_TO_ADDRESS (mail.reply_to.address) to hello@ or the public support address on the same domain as MAIL_FROM_ADDRESS (last two labels).');
    }

    private function mailDomain(string $address): string
    {
        $domain = substr($address, strrpos($address, '@') + 1);

        return implode('.', array_slice(explode('.', $domain), -2));
    }

    private function sendsMail(): bool
    {
        foreach ($this->mailers((string) $this->config->get('mail.default')) as $mailer) {
            if (! in_array($mailer['transport'], ['log', 'array'], true)) {
                return true;
            }
        }

        return false;
    }

    private function validMailFrom(): bool
    {
        $address = strtolower(trim((string) $this->config->get('mail.from.address')));
        $domain = substr($address, strrpos($address, '@') + 1);

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false && $domain !== 'example.com' && ! str_ends_with($domain, '.example.com');
    }

    private function production(): bool
    {
        return $this->config->get('app.env') === 'production';
    }

    private function set(string $key): bool
    {
        $value = $this->config->get($key);

        return is_string($value) && trim($value) !== '';
    }

    private function result(bool $passes, string $message): Result
    {
        return $passes ? Result::pass($message) : Result::fail($message);
    }
}

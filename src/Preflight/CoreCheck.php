<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tey\Standards\CiTriggers;
use Throwable;

final readonly class CoreCheck implements Check
{
    public const NAMES = ['app.key', 'app.env-debug', 'database.config', 'mail.transport', 'mail.from', 'mail.reply-to', 'queue.connection', 'queue.worker', 'cache.store', 'app.url', 'logging.channel', 'brand.favicon', 'ci.triggers'];

    /**
     * SHA-256 of upstream public/favicon.ico, favicon.svg and apple-touch-icon.png.
     * All reachable branches and tags in full Git mirrors of laravel/laravel and
     * laravel/{vue,react,svelte,livewire}-starter-kit audited on 2026-10-13.
     * Sources pin each distinct version; duplicate bytes appear only once.
     * README's known-default table includes reference-only SHA-1 values.
     *
     * @var list<string>
     */
    public const KNOWN_DEFAULT_HASHES = [
        // laravel/laravel@26730cd5b3ceae03ffc5549e85b67d9c94e861ef public/favicon.ico (empty).
        'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        // laravel/vue-starter-kit@e09dac8da170bba3eabd72a38cbc1c3f12171d6b public/favicon.ico.
        '4606a56e6ef3f5ec39201497f57069d5457ce9cea25227134d0ba378788e9070',
        // Same commit, public/favicon.svg.
        '242f4f8f93f5fbc6c8aeec500c9bac02dcfe68daba166d484c5cdc986c88d8ee',
        // Same commit, public/apple-touch-icon.png.
        '4001aa032ff113e1a268a9bbf1ab0fd9949439f9f54a85895956eb323aba977d',
        // laravel/vue-starter-kit@dc5bb6d1e2739156059b962f516369fb34f812d1 public/favicon.ico.
        'bf8593aec29b1ed018f6ea5299eadd19eaccf5afce3a23bec2ac9592de73ff4c',
        // Same commit, public/favicon.svg.
        '0d1a66fd5d0a10327508953be9c6fa502a3d9d466fb505a934df06cffbddf8a2',
        // Same commit, public/apple-touch-icon.png.
        'c359b8254a8a8a0137d9ec725627d28dbbbb1b543fca813e23d76a61870399f3',
        // laravel/react-starter-kit@108963ea46bf55045a73504b57ecff70ee2181a4 public/favicon.ico.
        '49eacef4006afcbef309d58d5e134dc118b993faa7a5a00d25c24048b2feb9cc',
        // Same commit, public/favicon.svg.
        '911847ef8aab1657b6395cbceca1f21bfd860e5736dc5d059ff3ba8c2270c1fb',
        // Same commit, public/apple-touch-icon.png.
        '61a028f9d9e1fb30365982c5b9a5f283d6847c0f9906b9127d991d23c1e814e5',
        // laravel/livewire-starter-kit@50ff1b57279c6c93dc0d7f40269c8b8170f07493 public/favicon.ico.
        '10712d787bbfd3c49b98a60839220ba4a10fa02c8b8b7e5c1f3d7eb61171f0dc',
        // Same commit, public/favicon.svg.
        'da6a60c37187f21fc7152793daf4b4685626554e85c7de3f0a6e192def174ff0',
        // Same commit, public/apple-touch-icon.png.
        'cf5ee2ae15971b85a6850289b9176d4a23e9e69c6acace8aeb206bb946bfe947',
        // laravel/laravel@a188d62105532fcf2a2839309fb71b862d904612 public/favicon.ico (blob 90c94b357efdbf349103c0a0ab232c3dd4e21602).
        '1b8a202e0e4965bf8fd5740b95d4678d8183eba32b8f203acc4ec3be9016245e',
        // laravel/laravel@6285eea340ae2756213f2d879f30fcc9cc8110bb public/favicon.ico.
        '8c7bdd0465674177c249e68b8cf92a57b9071997d48e2283c47a69db6680b3ca',
        // laravel/laravel@d5355a7a52eaad2695fee8ea0aefb0197b5dc38a public/favicon.ico.
        '382862f617fca26f9ccaeb98dc3944d6f977a43af3c15f2df5605c45ea23a8c5',
        // laravel/laravel@90b33ddc1aff273ecb6c31d97f075060ea7aed41 public/favicon.ico.
        '8ada36385f6d68aa53f6fab3865588c4696f72f4cd55c50c4457d2d3d5cf8380',
        // laravel/laravel@67fc2af3f4de208c3407da8eaa3dc1fb6d50847b public/favicon.ico.
        '2194a04330d5715154b60ec604ff539b10570baffe8e2427dc485b12cc0d64b9',
        // laravel/react-starter-kit@fe4281b79e6f4a528287f0f580343cc777ea897c public/favicon.svg.
        '99ef06a5e4e694047ee33176b8eef29ee5867fbec6af7b45caa273d6571fe1cc',
    ];

    private const TRANSPORTS = [
        'resend' => [['resend/resend-php'], ['services.resend.key' => 'RESEND_API_KEY']],
        'postmark' => [['symfony/postmark-mailer', 'symfony/http-client'], ['services.postmark.key' => 'POSTMARK_API_KEY']],
        'mailgun' => [['symfony/mailgun-mailer', 'symfony/http-client'], ['services.mailgun.secret' => 'MAILGUN_SECRET', 'services.mailgun.domain' => 'MAILGUN_DOMAIN']],
        'ses' => [['aws/aws-sdk-php'], ['services.ses.key' => 'AWS_ACCESS_KEY_ID', 'services.ses.secret' => 'AWS_SECRET_ACCESS_KEY']],
        'ses-v2' => [['aws/aws-sdk-php'], ['services.ses.key' => 'AWS_ACCESS_KEY_ID', 'services.ses.secret' => 'AWS_SECRET_ACCESS_KEY']],
    ];

    public function __construct(private string $check, private Repository $config, private string $base) {}

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
        if (in_array($this->check, ['app.key', 'queue.worker'], true)) {
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
            'queue.connection' => $this->queueConnection(),
            'queue.worker' => $this->queueWorker(),
            'cache.store' => $this->result(! $this->production() || $this->config->get('cache.default') !== 'array', 'Set CACHE_STORE to a persistent store in production.'),
            'app.url' => $this->result($this->set('app.url') && (! $this->production() || (filter_var($this->config->get('app.url'), FILTER_VALIDATE_URL) !== false && parse_url((string) $this->config->get('app.url'), PHP_URL_SCHEME) === 'https')), 'Set APP_URL to the public HTTPS URL in production.'),
            'logging.channel' => $this->result($this->set('logging.default') && $this->set('logging.channels.'.$this->config->get('logging.default').'.driver'), 'Set LOG_CHANNEL to a configured logging channel.'),
            'brand.favicon' => $this->favicon(),
            'ci.triggers' => $this->ciTriggers(),
            default => throw new InvalidArgumentException('Unknown core check: '.$this->check),
        };
    }

    private function ciTriggers(): Result
    {
        $parser = new CiTriggers($this->base, $this->config->get('preflight.ci.default_branches', ['main']), $this->config->get('preflight.ci.distribution'));
        $remedy = 'Apply docs/recipes/backend/ci-triggers.md; see tey/standards README (CI triggers) for findings and configuration.';

        if (! $this->config->get('preflight.ci.enforce', false)) {
            $remedy .= ' Rule 51 is advisory until PREFLIGHT_CI_ENFORCE=true; fix or ratchet findings before opting in.';
        }

        if ($parser->workflows() === []) {
            return Result::warn('Unknown: no .github/workflows/*.yml or *.yaml files found. '.$remedy);
        }

        $findings = $parser->findings();
        $advisories = implode(' ', array_map(fn (array $finding): string => 'Advisory '.$finding['workflow'].' ['.$finding['job'].'] '.$finding['reason'], $parser->advisories()));

        if ($findings !== []) {
            $first = array_map(fn (array $finding): string => $finding['workflow'].' ['.$finding['job'].'] '.$finding['reason'], array_slice($findings, 0, 5));

            return Result::fail(implode(' ', $first).(count($findings) > 5 ? ' And '.(count($findings) - 5).' more findings.' : '').' '.$advisories.' '.$remedy);
        }

        $message = 'Workflow triggers, concurrency, draft guards and timeouts conform. '.$advisories.' Advisory docs-skip: review paths-ignore or a changes job against required checks online.';

        return $advisories === '' ? Result::pass($message) : Result::warn($message);
    }

    private function favicon(): Result
    {
        $remedy = "Ship the app's own favicon.svg, a non-empty favicon.ico and a 180×180 apple-touch-icon.png, and link them in every root layout.";
        $hashes = [...self::KNOWN_DEFAULT_HASHES, ...$this->config->get('preflight.brand.known_default_hashes', [])];

        foreach (['favicon.ico', 'favicon.svg', 'apple-touch-icon.png'] as $name) {
            $path = $this->base.'/public/'.$name;

            if (! is_file($path) || ! is_readable($path) || filesize($path) === 0) {
                return Result::fail('public/'.$name.' is missing, unreadable or empty. '.$remedy);
            }

            if (in_array(hash_file('sha256', $path), $hashes, true)) {
                return Result::fail('public/'.$name.' matches a Laravel default. '.$remedy);
            }
        }

        $ico = (string) file_get_contents($this->base.'/public/favicon.ico');

        if (! str_starts_with($ico, "\x00\x00\x01\x00")) {
            return Result::fail('public/favicon.ico must start with the ICO header 00 00 01 00. '.$remedy);
        }

        if (strlen($ico) < 6) {
            return Result::fail('public/favicon.ico has a truncated ICO directory count. '.$remedy);
        }

        $count = ord($ico[4]) + (ord($ico[5]) << 8);

        if (strlen($ico) < 6 + $count * 16) {
            return Result::fail('public/favicon.ico has a truncated ICO directory. '.$remedy);
        }

        $sizes = [];

        for ($index = 0; $index < $count; $index++) {
            $width = ord($ico[6 + $index * 16]) ?: 256;
            $height = ord($ico[7 + $index * 16]) ?: 256;
            $sizes[] = $width.'×'.$height;
        }

        if (! in_array('16×16', $sizes, true) || ! in_array('32×32', $sizes, true)) {
            return Result::fail('public/favicon.ico must contain 16×16 and 32×32 directory entries; found '.(implode(', ', $sizes) ?: 'none').'. '.$remedy);
        }

        $path = $this->base.'/public/apple-touch-icon.png';
        $png = file_get_contents($path, false, null, 0, 8) === "\x89PNG\r\n\x1a\n" ? @getimagesize($path) : false;

        if ($png === false || $png[2] !== IMAGETYPE_PNG) {
            return Result::fail('public/apple-touch-icon.png must be a PNG image. '.$remedy);
        }

        if ($png[0] !== 180 || $png[1] !== 180) {
            return Result::fail('public/apple-touch-icon.png must be 180×180 pixels; found '.$png[0].'×'.$png[1].'. '.$remedy);
        }

        $svg = preg_replace('/<!--.*?-->|<\?.*?\?>/s', '', (string) file_get_contents($this->base.'/public/favicon.svg')) ?? '';

        if (preg_match('/<([A-Za-z][\w:.-]*)\b/', $svg, $root) !== 1 || $root[1] !== 'svg') {
            return Result::fail('public/favicon.svg must contain an <svg root element. '.$remedy);
        }

        $layouts = $this->config->get('preflight.brand.layouts', []);

        if ($layouts === []) {
            $view = $this->config->get('inertia.root_view') ?? 'app';
            $layouts = ['resources/views/'.str_replace('.', '/', $view).'.blade.php'];
        }

        $unknown = false;

        foreach ($layouts as $layout) {
            $path = $this->base.'/'.$layout;

            if (! is_file($path) || ! is_readable($path)) {
                $unknown = true;

                continue;
            }

            if (! $this->hasIconLink((string) file_get_contents($path))) {
                return Result::fail('Root layout '.$layout.' has no non-data icon link. '.$remedy);
            }
        }

        return $unknown
            ? Result::warn('Unknown: a root layout could not be found or read. Configure preflight.brand.layouts. '.$remedy)
            : Result::pass('Favicon files have the required formats and sizes, are non-default and every inspected root layout has an icon link.');
    }

    private function hasIconLink(string $source): bool
    {
        $source = preg_replace('/{{--.*?--}}|<!--.*?-->/s', '', $source) ?? '';
        preg_match_all('/<link\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i', $source, $links);

        foreach ($links[0] as $link) {
            preg_match_all('/\s([\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $link, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            $attributes = [];

            foreach ($matches as $match) {
                $attributes[strtolower($match[1])] = strtolower(trim($match[2] ?? $match[3] ?? $match[4] ?? ''));
            }

            $href = $attributes['href'] ?? '';

            if (in_array('icon', preg_split('/\s+/', $attributes['rel'] ?? '') ?: [], true) && $href !== '' && ! str_starts_with($href, 'data:')) {
                return true;
            }
        }

        return false;
    }

    private function database(): Result
    {
        $connection = 'database.connections.'.$this->config->get('database.default');
        $configured = $this->set('database.default') && $this->set($connection.'.driver')
            && ($this->set($connection.'.database') || $this->set($connection.'.url'));

        return $this->result($configured, 'Set DB_CONNECTION and its database or DB_URL configuration.');
    }

    private function syncQueue(): bool
    {
        return $this->config->get('queue.connections.'.$this->config->get('queue.default').'.driver', $this->config->get('queue.default')) === 'sync';
    }

    private function queueConnection(): Result
    {
        $expected = $this->config->get('preflight.queue.expect_worker');

        if ($this->syncQueue() && $expected === true) {
            return Result::warn("A worker is expected but the queue runs synchronously. Enable the hosting platform's managed queue (Laravel Cloud managed queues) and configure its async connection, or keep QUEUE_CONNECTION=sync deliberately and set PREFLIGHT_QUEUE_EXPECT_WORKER=false.");
        }

        if (! $this->syncQueue() && $expected === false) {
            return Result::warn("A queue is configured but no worker is expected. Set QUEUE_CONNECTION=sync deliberately, or enable the hosting platform's managed queue (Laravel Cloud managed queues) for this connection and set PREFLIGHT_QUEUE_EXPECT_WORKER=true.");
        }

        return Result::pass('Queue connection matches the sync until needed policy.');
    }

    private function queueWorker(): Result
    {
        if ($this->syncQueue()) {
            return Result::pass('Worker backlog check is not applicable to a synchronous queue.');
        }

        $connection = $this->config->get('queue.connections.'.$this->config->get('queue.default'), []);
        $driver = $connection['driver'] ?? '';

        if ($driver === 'cloud') {
            return Result::pass('Managed by the platform (Laravel Cloud managed queue); no worker to check.');
        }

        if ($this->config->get('preflight.queue.expect_worker') === false) {
            return $this->queueConnection();
        }

        $queue = $connection['queue'] ?? 'default';
        $now = Carbon::now()->getTimestamp();

        try {
            if ($driver === 'database') {
                /** @var DatabaseManager $database */
                $database = Container::getInstance()->make('db');
                $oldest = $database->connection($connection['connection'] ?? null)
                    ->table($connection['table'] ?? 'jobs')
                    ->where('queue', $queue)
                    ->whereNull('reserved_at')
                    ->where('available_at', '<=', $now)
                    ->oldest('available_at')
                    ->value('available_at');

                if ($oldest === null) {
                    return Result::pass('No available jobs are waiting; this does not prove a worker is running.');
                }
            } elseif ($driver === 'redis') {
                /** @var RedisFactory $redis */
                $redis = Container::getInstance()->make('redis');
                $client = $redis->connection($connection['connection'] ?? 'default');
                $key = 'queues:'.($client->isCluster() && ! RedisConnection::hasHashTag($queue) ? '{'.$queue.'}' : $queue);
                $script = "return {redis.call('LLEN', KEYS[1]), redis.call('LINDEX', KEYS[1], 0)}";
                [$length, $payload] = $client->command('eval', $client instanceof PhpRedisConnection ? [$script, [$key], 1] : [$script, 1, $key]);

                if ($length === 0) {
                    return Result::pass('No ready jobs are waiting; this does not prove a worker is running.');
                }

                $oldest = is_string($payload) ? (json_decode($payload, true)['createdAt'] ?? null) : null;

                if (! is_int($oldest) || $oldest <= 0) {
                    return $this->unknownQueue('Redis has waiting jobs but their oldest age cannot be determined.');
                }
            } else {
                return $this->unknownQueue('Backlog inspection is unsupported for the configured queue driver.');
            }
        } catch (Throwable) {
            return $this->unknownQueue('Queue connection or backlog read failed; check the connection configuration (exception details withheld to protect secrets).');
        }

        $minutes = $this->config->get('preflight.queue.max_backlog_minutes', 15);

        return $now - (int) $oldest > $minutes * 60
            ? Result::warn('Available jobs have waited longer than '.$minutes." minutes. Enable the hosting platform's managed queue (Laravel Cloud managed queues) for this connection, or set QUEUE_CONNECTION=sync deliberately for future jobs; review the existing backlog.")
            : Result::pass('Available backlog is within '.$minutes.' minutes; this does not prove a worker is running.');
    }

    private function unknownQueue(string $reason): Result
    {
        return Result::warn('Unknown: '.$reason." Verify the hosting platform's managed queue (Laravel Cloud managed queues) is enabled for this connection, or set QUEUE_CONNECTION=sync deliberately for future jobs; review any existing backlog.");
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

<?php

declare(strict_types=1);

namespace Tey\Standards\Preflight;

use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tey\Standards\CiTriggers;
use Throwable;

/**
 * Deploy-blocking proof that every configured driver can run: its package or
 * extension is installed, its tables exist and its secrets are well-formed.
 *
 * Packages are read from Composer's installed metadata (InstalledVersions),
 * never guessed from class names. PHP extensions are probed with
 * extension_loaded(), because phpredis, memcached, gd and imagick ship no
 * Composer package. No check opens a network connection except install.tables,
 * which reads the schema on the configured database connection.
 */
final readonly class InstallCheck implements Check
{
    public const NAMES = ['install.mail', 'install.filesystems', 'install.drivers', 'install.tables', 'install.extensions', 'install.secrets', 'install.vite', 'install.sessions'];

    private const MAIL_PACKAGES = [
        'resend' => ['resend/resend-php'],
        'postmark' => ['symfony/postmark-mailer', 'symfony/http-client'],
        'mailgun' => ['symfony/mailgun-mailer', 'symfony/http-client'],
        'ses' => ['aws/aws-sdk-php'],
        'ses-v2' => ['aws/aws-sdk-php'],
    ];

    private const DISK_PACKAGES = [
        's3' => 'league/flysystem-aws-s3-v3',
        'ftp' => 'league/flysystem-ftp',
        'sftp' => 'league/flysystem-sftp-v3',
    ];

    /** The field that shows a disk was configured for use, not just left in the stock config. */
    private const DISK_MARKERS = ['s3' => 'bucket', 'ftp' => 'host', 'sftp' => 'host'];

    private const PLACEHOLDERS = ['placeholder', 'changeme', 'change-me', 'change_me', 'secret'];

    public function __construct(private string $check, private Repository $config, private string $base) {}

    public function name(): string
    {
        return $this->check;
    }

    public function level(): Level
    {
        return Level::Blocking;
    }

    public function needsSecrets(): bool
    {
        // Tables need the production database; secrets need the production environment.
        return in_array($this->check, ['install.tables', 'install.secrets', 'install.sessions'], true);
    }

    public function run(): Result
    {
        if ((new CiTriggers($this->base, distribution: $this->config->get('preflight.ci.distribution')))->distribution() === 'package') {
            return new Result(Status::Skipped, 'Not applicable to packages.');
        }

        return match ($this->check) {
            'install.mail' => $this->mail(),
            'install.filesystems' => $this->filesystems(),
            'install.drivers' => $this->drivers(),
            'install.tables' => $this->tables(),
            'install.extensions' => $this->extensions(),
            'install.secrets' => $this->secrets(),
            'install.vite' => $this->vite(),
            'install.sessions' => $this->sessions(),
            default => throw new InvalidArgumentException('Unknown install check: '.$this->check),
        };
    }

    private function mail(): Result
    {
        foreach ($this->mailers((string) $this->config->get('mail.default')) as $name => $transport) {
            foreach (self::MAIL_PACKAGES[$transport] ?? [] as $package) {
                if (! InstalledVersions::isInstalled($package)) {
                    return Result::fail('mail.mailers.'.$name.' uses the '.$transport.' transport; install '.$package.'.');
                }
            }
        }

        return Result::pass('Every transport the default mailer resolves to has its package.');
    }

    /**
     * The default mailer and, for failover and roundrobin, every child.
     *
     * @param  list<string>  $seen
     * @return array<string, string>
     */
    private function mailers(string $name, array $seen = []): array
    {
        $transport = $this->config->get('mail.mailers.'.$name.'.transport');

        if (in_array($name, $seen, true) || ! is_string($transport)) {
            return [];
        }

        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return [$name => $transport];
        }

        $mailers = [];

        foreach ((array) $this->config->get('mail.mailers.'.$name.'.mailers', []) as $child) {
            $mailers += $this->mailers((string) $child, [...$seen, $name]);
        }

        return $mailers;
    }

    private function filesystems(): Result
    {
        $disks = (array) $this->config->get('filesystems.disks', []);
        $used = array_filter([
            $this->config->get('filesystems.default'),
            $this->config->get('filesystems.cloud'),
            $this->config->get('media-library.disk_name'),
            $this->config->get('media-library.conversions_disk_name'),
        ], 'is_string');

        foreach ($disks as $name => $disk) {
            $driver = $this->diskDriver((string) $name);
            $marker = self::DISK_MARKERS[$driver] ?? null;

            if (! in_array($name, $used, true) && ($marker === null || ! $this->filled($disk[$marker] ?? null))) {
                continue;
            }

            if (isset(self::DISK_PACKAGES[$driver]) && ! InstalledVersions::isInstalled(self::DISK_PACKAGES[$driver])) {
                return Result::fail('filesystems.disks.'.$name.' uses the '.$driver.' driver; install '.self::DISK_PACKAGES[$driver].'.');
            }
        }

        foreach ($used as $name) {
            if (! isset($disks[$name])) {
                return Result::fail('The '.$name.' disk is selected but filesystems.disks.'.$name.' is not configured.');
            }
        }

        return Result::pass('Every selected disk, and every disk with a bucket or host, has its Flysystem adapter.');
    }

    /** @param  list<string>  $seen */
    private function diskDriver(string $name, array $seen = []): string
    {
        $driver = (string) $this->config->get('filesystems.disks.'.$name.'.driver');
        $parent = $this->config->get('filesystems.disks.'.$name.'.disk');

        return $driver === 'scoped' && is_string($parent) && ! in_array($parent, $seen, true)
            ? $this->diskDriver($parent, [...$seen, $name])
            : $driver;
    }

    private function drivers(): Result
    {
        $queue = (string) $this->config->get('queue.default');
        $store = (string) $this->config->get('cache.default');
        $uses = [
            'queue.connections.'.$queue => [$this->config->get('queue.connections.'.$queue.'.driver'), $this->config->get('queue.connections.'.$queue.'.connection')],
            'session.driver' => [$this->config->get('session.driver'), $this->config->get('session.connection')],
        ];

        foreach ($this->stores($store) as $name) {
            $uses['cache.stores.'.$name] = [$this->config->get('cache.stores.'.$name.'.driver'), $this->config->get('cache.stores.'.$name.'.connection')];
        }

        foreach ($uses as $key => [$driver]) {
            $missing = match ($driver) {
                'redis' => $this->redisClient(),
                'sqs', 'dynamodb' => InstalledVersions::isInstalled('aws/aws-sdk-php') ? null : 'the aws/aws-sdk-php package',
                'memcached' => extension_loaded('memcached') ? null : 'the memcached PHP extension',
                'beanstalkd' => InstalledVersions::isInstalled('pda/pheanstalk') ? null : 'the pda/pheanstalk package',
                default => null,
            };

            if ($missing !== null) {
                return Result::fail($key.' uses the '.$driver.' driver; install '.$missing.'.');
            }
        }

        return Result::pass('The default queue connection, cache store and session driver have their packages or extensions.');
    }

    /**
     * The default store and, for failover, every store it falls back to.
     *
     * @param  list<string>  $seen
     * @return list<string>
     */
    private function stores(string $name, array $seen = []): array
    {
        if (in_array($name, $seen, true) || $this->config->get('cache.stores.'.$name.'.driver') !== 'failover') {
            return [$name];
        }

        $stores = [];

        foreach ((array) $this->config->get('cache.stores.'.$name.'.stores', []) as $child) {
            $stores = [...$stores, ...$this->stores((string) $child, [...$seen, $name])];
        }

        return $stores;
    }

    private function redisClient(): ?string
    {
        $client = $this->config->get('database.redis.client', 'phpredis');

        return match ($client) {
            'predis' => InstalledVersions::isInstalled('predis/predis') ? null : 'the predis/predis package (database.redis.client is predis)',
            default => extension_loaded('redis') ? null : 'the phpredis PHP extension (database.redis.client is '.$client.')',
        };
    }

    private function tables(): Result
    {
        $required = [];
        $session = $this->config->get('session.driver');
        $queue = (string) $this->config->get('queue.default');
        $connection = $this->config->get('queue.connections.'.$queue, []);

        if ($session === 'database') {
            $required[] = [$this->config->get('session.connection'), $this->config->get('session.table', 'sessions'), 'session.table'];
        }

        foreach ($this->stores((string) $this->config->get('cache.default')) as $name) {
            if ($this->config->get('cache.stores.'.$name.'.driver') === 'database') {
                $store = (array) $this->config->get('cache.stores.'.$name);
                $required[] = [$store['connection'] ?? null, $store['table'] ?? 'cache', 'cache.stores.'.$name.'.table'];
                $required[] = [$store['lock_connection'] ?? $store['connection'] ?? null, $store['lock_table'] ?? 'cache_locks', 'cache.stores.'.$name.'.lock_table'];
            }
        }

        if (is_array($connection) && ($connection['driver'] ?? null) === 'database') {
            $required[] = [$connection['connection'] ?? null, $connection['table'] ?? 'jobs', 'queue.connections.'.$queue.'.table'];
            // A database queue is where batches run; other queues may batch without the table.
            $required[] = [$this->config->get('queue.batching.database'), $this->config->get('queue.batching.table', 'job_batches'), 'queue.batching.table'];
        }

        if (in_array($this->config->get('queue.failed.driver'), ['database', 'database-uuids'], true)) {
            $required[] = [$this->config->get('queue.failed.database'), $this->config->get('queue.failed.table', 'failed_jobs'), 'queue.failed.table'];
        }

        if ($required === []) {
            return Result::pass('No session, cache, queue or failed-job storage uses the database.');
        }

        $missing = [];

        foreach ($required as [$name, $table, $key]) {
            $name = is_string($name) && $name !== '' ? $name : null;

            try {
                $exists = Container::getInstance()->make('db')->connection($name)->getSchemaBuilder()->hasTable((string) $table);
            } catch (Throwable) {
                return Result::fail('Not checked: the '.($name ?? 'default').' database connection could not be read for '.$key.' (exception details withheld to protect secrets).');
            }

            if (! $exists) {
                $missing[] = $table.' ('.$key.')';
            }
        }

        return $missing === []
            ? Result::pass('Every table that database-backed drivers need exists.')
            : Result::fail('Missing tables: '.implode(', ', array_unique($missing)).'. Run the migrations that create them.');
    }

    private function extensions(): Result
    {
        [$required, $source] = $this->requiredExtensions();

        foreach ($required as $extension => $reason) {
            if (! $this->loaded($extension)) {
                return Result::fail('Enable the '.$extension.' PHP extension ('.$reason.').');
            }
        }

        $image = $this->imageDriver();

        if ($image !== null && ! extension_loaded($image[0])) {
            return Result::fail('Enable the '.$image[0].' PHP extension ('.$image[1].').');
        }

        return Result::pass('Every PHP extension required by '.$source.' is loaded.');
    }

    /** @return array{array<string, string>, string} */
    private function requiredExtensions(): array
    {
        $required = [];
        $composer = $this->json($this->base.'/composer.json');

        foreach (array_keys((array) ($composer['require'] ?? [])) as $name) {
            if (str_starts_with((string) $name, 'ext-')) {
                $required[substr((string) $name, 4)] = 'composer.json requires '.$name;
            }
        }

        $lock = $this->json($this->base.'/composer.lock');

        if ($lock !== null) {
            foreach ((array) ($lock['packages'] ?? []) as $package) {
                foreach (array_keys((array) ($package['require'] ?? [])) as $name) {
                    if (str_starts_with((string) $name, 'ext-')) {
                        $required[substr((string) $name, 4)] ??= ($package['name'] ?? 'a locked package').' requires '.$name;
                    }
                }
            }

            return [$required, 'composer.json and composer.lock'];
        }

        // Fallback without a lock file: scan app/ for the two extensions apps most often use implicitly.
        foreach (['bcmath' => '/\bbc(?:add|sub|mul|div|mod|pow|powmod|sqrt|comp|scale)\s*\(/', 'intl' => '/\b(?:NumberFormatter|Collator|IntlDateFormatter|Normalizer::|Number::(?:format|currency|percentage|spell|ordinal|fileSize|forHumans))/'] as $extension => $pattern) {
            if ($this->sourceMatches($pattern)) {
                $required[$extension] ??= 'app/ calls it; no composer.lock to read';
            }
        }

        return [$required, 'composer.json and a scan of app/ (no composer.lock)'];
    }

    /** @return array{string, string}|null */
    private function imageDriver(): ?array
    {
        foreach (['image.driver', 'media-library.image_driver'] as $key) {
            $driver = $this->config->get($key);

            if ($key === 'media-library.image_driver' && (! InstalledVersions::isInstalled('spatie/laravel-medialibrary')
                || ! $this->sourceMatches('/\b(?:addMediaConversion|registerMediaConversions)\b/'))) {
                continue;
            }

            if (is_string($driver)) {
                $driver = strtolower(class_basename($driver));
                $driver = str_contains($driver, 'imagick') ? 'imagick' : (str_contains($driver, 'gd') ? 'gd' : null);

                if ($driver !== null) {
                    return [$driver, $key.' names it'.($key === 'media-library.image_driver' ? ' and app/ defines conversions' : '')];
                }
            }
        }

        return null;
    }

    private function loaded(string $extension): bool
    {
        return extension_loaded($extension) || extension_loaded(str_replace('-', ' ', $extension));
    }

    private function secrets(): Result
    {
        $key = $this->config->get('app.key');
        $cipher = strtolower((string) $this->config->get('app.cipher', 'aes-256-cbc'));
        $length = match ($cipher) {
            'aes-128-cbc', 'aes-128-gcm' => 16,
            'aes-256-cbc', 'aes-256-gcm' => 32,
            default => null,
        };

        if ($length === null) {
            return Result::fail('app.cipher is not a supported cipher.');
        }

        foreach (['app.key' => $key, ...array_combine(
            array_map(fn (int|string $index): string => 'app.previous_keys.'.$index, array_keys((array) $this->config->get('app.previous_keys', []))),
            array_values((array) $this->config->get('app.previous_keys', [])),
        )] as $name => $value) {
            if ($name === 'app.key' && ! $this->filled($value)) {
                return Result::fail('Set APP_KEY (php artisan key:generate).');
            }

            $decoded = is_string($value) && str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;

            if (! is_string($decoded) || strlen($decoded) !== $length) {
                return Result::fail($name.' does not decode to the '.$length.'-byte key '.$cipher.' needs (php artisan key:generate).');
            }
        }

        if (InstalledVersions::isInstalled('laravel/passport')) {
            foreach (['private' => 'passport.private_key', 'public' => 'passport.public_key'] as $kind => $name) {
                $value = $this->config->get($name);
                $source = $name;

                if (! $this->filled($value)) {
                    $path = rtrim((string) ($this->config->get('passport.key_path') ?? $this->base.'/storage'), '/').'/oauth-'.$kind.'.key';
                    $value = is_readable($path) ? file_get_contents($path) : null;
                    $source = 'the oauth-'.$kind.'.key file';
                }

                if (! is_string($value) || ! str_contains($value, '-----BEGIN ') || ! str_contains($value, "\n") || str_contains($value, '\n')) {
                    return Result::fail($source.' is not a PEM key with real newlines (php artisan passport:keys, or quote the env value).');
                }
            }
        }

        $placeholders = $this->placeholders((array) $this->config->all());

        if ($placeholders !== []) {
            return Result::fail('Set real values for these secrets, which hold a stand-in: '.implode(', ', array_slice($placeholders, 0, 5)).(count($placeholders) > 5 ? ' and '.(count($placeholders) - 5).' more' : '').'.');
        }

        foreach ($this->mailers((string) $this->config->get('mail.default')) as $name => $transport) {
            $mailer = (array) $this->config->get('mail.mailers.'.$name);
            [$field, $value] = match ($transport) {
                'resend' => ['services.resend.key', $mailer['key'] ?? $this->config->get('services.resend.key')],
                'postmark' => ['services.postmark.key', $mailer['token'] ?? $mailer['key'] ?? $this->config->get('services.postmark.token') ?? $this->config->get('services.postmark.key')],
                'mailgun' => ['services.mailgun.secret', $mailer['secret'] ?? $this->config->get('services.mailgun.secret')],
                default => [null, null],
            };

            if ($field !== null && ! $this->filled($value)) {
                return Result::fail($field.' (or its mailer override) is empty but mail.mailers.'.$name.' uses the '.$transport.' transport.');
            }
        }

        return Result::pass('APP_KEY decodes to the cipher length, no secret is a placeholder and required driver secrets are set.');
    }

    /**
     * Config keys whose name marks a secret and whose value is a known placeholder.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function placeholders(array $values, string $prefix = ''): array
    {
        $found = [];

        foreach ($values as $name => $value) {
            $key = $prefix.$name;

            if (is_array($value)) {
                $found = [...$found, ...$this->placeholders($value, $key.'.')];
            } elseif (is_string($value) && preg_match('/(?:^|[._-])(?:key|secret|token|password|passphrase)\z/i', (string) $name) === 1
                && (preg_match('/\A\*+\z/', trim($value)) === 1 || in_array(strtolower(trim($value)), self::PLACEHOLDERS, true))) {
                $found[] = $key;
            }
        }

        return $found;
    }

    /**
     * OAuth consent keeps the authorization request in the session. A cookie
     * session passes the browser's 4KB limit, the cookie is dropped and the
     * consent fails, so the driver must be a server-side store, set per
     * environment rather than left to the platform's default.
     */
    private function sessions(): Result
    {
        $consent = InstalledVersions::isInstalled('laravel/passport') || (bool) $this->config->get('preflight.sessions.consent', false);

        if (! $consent || in_array($this->config->get('app.env'), ['local', 'testing'], true)) {
            return Result::pass('No OAuth consent screen is installed.');
        }

        if ($this->config->get('session.driver') === 'cookie') {
            return Result::fail('SESSION_DRIVER is cookie; OAuth consent outgrows the 4KB cookie. Set SESSION_DRIVER=database (or redis) in this environment.');
        }

        if (! $this->filled(Env::get('SESSION_DRIVER'))) {
            return Result::fail('SESSION_DRIVER is not set in this environment; set SESSION_DRIVER=database (or redis) explicitly for OAuth consent.');
        }

        return Result::pass('The session driver is set explicitly and is not cookie.');
    }

    private function vite(): Result
    {
        if (! $this->usesVite()) {
            return Result::pass('No Blade view under resources/views uses @vite.');
        }

        $directory = trim((string) $this->config->get('preflight.vite.build_directory', 'build'), '/');
        $manifest = 'public/'.$directory.'/manifest.json';

        if ($this->json($this->base.'/'.$manifest) === null) {
            return Result::fail($manifest.' is missing or not valid JSON; run the asset build (npm run build) before deploying.');
        }

        return Result::pass($manifest.' exists for the @vite views.');
    }

    private function usesVite(): bool
    {
        $views = $this->base.'/resources/views';

        if (! is_dir($views)) {
            return false;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php') && str_contains((string) file_get_contents($file->getPathname()), '@vite')) {
                return true;
            }
        }

        return false;
    }

    private function sourceMatches(string $pattern): bool
    {
        $app = $this->base.'/app';

        if (! is_dir($app)) {
            return false;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<mixed>|null */
    private function json(string $path): ?array
    {
        $data = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : null;
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

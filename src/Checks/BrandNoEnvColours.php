<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Symfony\Component\Process\Process;
use Tey\Standards\Configuration;

/**
 * Rule `brand.no-env-colours` (warn only): brand settings live in a committed config/brand.php, not in `KIT_BRAND_*` env keys.
 * Env keeps only secrets and per-environment values. It warns, per key and file, when:
 * - `.env.example`, or any other `.env.*` file git tracks, sets a `KIT_BRAND_*` key (a commented-out line doesn't count). `.env` itself is never read,
 *   tracked or not, and an untracked env file other than `.env.example` is the machine's own and is left alone. Without git (or the base is not
 *   its own repository's root), only `.env.example` is read;
 * - config/kit.php or config/brand.php reads a brand value with `env('KIT_BRAND_…')` (read from PHP tokens, so comments and docblocks don't count).
 *
 * Every finding is advisory: the rule warns and is never enforced, so listing it in Configuration::$optionalRules changes nothing.
 * It becomes an error in the tey/kit 0.16 era, once every app has moved its brand into config/brand.php.
 */
final readonly class BrandNoEnvColours
{
    public const RULE = 'brand.no-env-colours';

    private const CONFIG = ['config/kit.php', 'config/brand.php'];

    private const FIX = 'run `php artisan kit:brand:migrate`, commit config/brand.php, then delete the env lines. Env keeps only secrets and per-environment values; this warning becomes an error in the tey/kit 0.16 era.';

    public function __construct(private Configuration $config) {}

    /** @return list<array{target: string, check: string, line: int, hint: string, advisory: true}> */
    public function violations(): array
    {
        $found = [];
        foreach ($this->envFiles() as $path) {
            foreach ($this->envKeys($path) as [$key, $line]) {
                $found[] = $this->finding($path, $line, "{$path} sets brand key {$key}: brand settings belong in config/brand.php, not env");
            }
        }
        foreach (self::CONFIG as $path) {
            foreach ($this->envReads($path) as [$key, $line]) {
                $found[] = $this->finding($path, $line, "{$path} reads brand key {$key} with env('{$key}'): brand settings belong in config/brand.php as committed values");
            }
        }

        return $found;
    }

    /**
     * The committed env files: .env.example (whenever it exists) and every other `.env.*` git tracks at the base. Never `.env`.
     *
     * @return list<string>
     */
    private function envFiles(): array
    {
        $files = is_file($this->config->base.'/.env.example') ? ['.env.example'] : [];
        foreach ($this->tracked() as $path) {
            if (str_starts_with($path, '.env.') && ! str_contains($path, '/') && ! in_array($path, $files, true)) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Root-level `.env*` paths git tracks, or none when the base is not the root of its own git work tree (or git can't answer).
     *
     * @return list<string>
     */
    private function tracked(): array
    {
        $root = $this->git(['rev-parse', '--show-toplevel']);
        if ($root === null || realpath(trim($root)) !== realpath($this->config->base)) {
            return [];
        }
        $output = $this->git(['ls-files', '-z', '--', '.env*']);

        return $output === null ? [] : array_values(array_filter(explode("\0", $output), fn (string $path): bool => $path !== ''));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): ?string
    {
        if (! is_dir($this->config->base)) {
            return null;
        }
        $process = new Process(['git', '-c', 'core.quotePath=false', ...$arguments], $this->config->base);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /**
     * Each `KIT_BRAND_*` key an env file sets, with its line.
     *
     * @return list<array{string, int}>
     */
    private function envKeys(string $path): array
    {
        $file = $this->config->base.'/'.$path;
        if (! is_file($file) || is_link($file)) {
            return [];
        }
        $keys = [];
        foreach (preg_split('~\R~', (string) file_get_contents($file)) ?: [] as $index => $line) {
            if (preg_match('~^\s*(?:export\s+)?(KIT_BRAND_[A-Za-z0-9_]*)\s*=~', $line, $match) === 1) {
                $keys[] = [$match[1], $index + 1];
            }
        }

        return $keys;
    }

    /**
     * Each `env('KIT_BRAND_…')` call in a config file, with its line: a call to `env` (or `\env`) whose first argument is that string literal.
     *
     * @return list<array{string, int}>
     */
    private function envReads(string $path): array
    {
        $file = $this->config->base.'/'.$path;
        if (! is_file($file) || is_link($file)) {
            return [];
        }
        $tokens = array_values(array_filter(
            \PhpToken::tokenize((string) file_get_contents($file)),
            fn (\PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $reads = [];
        foreach ($tokens as $i => $token) {
            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || strtolower(ltrim($token->text, '\\')) !== 'env') {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
                continue;
            }
            $open = $tokens[$i + 1] ?? null;
            $argument = $tokens[$i + 2] ?? null;
            if ($open?->text !== '(' || $argument === null || ! $argument->is(T_CONSTANT_ENCAPSED_STRING)) {
                continue;
            }
            $key = substr($argument->text, 1, -1);
            if (str_starts_with($key, 'KIT_BRAND_')) {
                $reads[] = [$key, $argument->line];
            }
        }

        return $reads;
    }

    /** @return array{target: string, check: string, line: int, hint: string, advisory: true} */
    private function finding(string $path, int $line, string $check): array
    {
        return ['target' => $path, 'check' => $check.'; '.self::FIX, 'line' => $line, 'hint' => 'Brand in config (brand.no-env-colours): '.self::FIX, 'advisory' => true];
    }
}

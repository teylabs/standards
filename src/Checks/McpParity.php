<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use JsonException;
use stdClass;

/** Validates declarations only; references are never executed or printed. */
final class McpParity
{
    private const CATEGORIES = ['success', 'authorization', 'validation', 'privacy', 'audit', 'retry', 'stale', 'confirmation', 'uncertain_outcome', 'handoff', 'status', 'no_bypass', 'error', 'recovery'];

    /** @var list<string> */
    private array $findings = [];

    /** @var list<string> */
    private array $locked = [];

    public function __construct(private readonly string $base, private readonly string $manifest = 'docs/mcp-capabilities.json') {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $this->findings = [];
        $this->locked = [];
        try {
            $lock = $this->readJson('composer.lock');
        } catch (JsonException) {
            $lock = null;
        }
        foreach (['packages', 'packages-dev'] as $key) {
            foreach (is_array($lock->{$key} ?? null) ? $lock->{$key} : [] as $package) {
                if (is_string($package->name ?? null)) {
                    $this->locked[] = $package->name;
                }
            }
        }

        if (! $this->localFile($this->manifest)) {
            $this->findings[] = 'capability inventory is missing or is a symlink';
        } else {
            try {
                $data = $this->readJson($this->manifest);
                if (! $data instanceof stdClass || ($data->version ?? null) !== 1) {
                    $this->findings[] = 'capability inventory needs version 1';
                } elseif (! is_array($data->inventory ?? null) || ! array_is_list($data->inventory) || $data->inventory === [] || ! is_array($data->capabilities ?? null) || ! array_is_list($data->capabilities) || $data->capabilities === []) {
                    $this->findings[] = 'non-empty UI inventory and capabilities are required';
                } else {
                    $this->inventory($data->inventory, $data->capabilities);
                }
            } catch (JsonException) {
                $this->findings[] = 'capability inventory is not readable JSON';
            }
        }

        return array_map(fn (string $check): array => ['target' => $this->manifest, 'check' => $check], $this->findings);
    }

    /** @param list<mixed> $inventory
     * @param  list<mixed>  $capabilities
     */
    private function inventory(array $inventory, array $capabilities): void
    {
        $ids = $uiPaths = $variantIds = [];
        foreach ($capabilities as $number => $capability) {
            $label = 'capability '.($number + 1);
            if (! $capability instanceof stdClass) {
                $this->findings[] = $label.' must be an object';

                continue;
            }
            $name = $capability->id ?? null;
            if (! $this->identifier($name) || in_array($name, $ids, true)) {
                $this->findings[] = $label.' needs a unique stable id';

                continue;
            }
            $ids[] = $name;
            $kind = $capability->kind ?? null;
            if (! in_array($kind, ['read', 'write', 'external'], true)) {
                $this->findings[] = $label.' needs read, write or external kind';
            }
            $ui = $capability->ui ?? null;
            if (! is_array($ui) || $ui === [] || count(array_filter($ui, $this->reference(...))) !== count($ui)) {
                $this->findings[] = $label.' needs existing UI source references';
            } else {
                $uiPaths[$name] = array_column($ui, 'path');
            }
            if (! $this->reference($capability->domain ?? null)) {
                $this->findings[] = $label.' needs an existing domain operation or binding reference';
            }
            $mcp = $capability->mcp ?? null;
            if (! $mcp instanceof stdClass || ($mcp->status ?? null) !== 'implemented') {
                $this->findings[] = $label.' has a missing or incomplete MCP path';

                continue;
            }
            if (! in_array($mcp->kind ?? null, ['tool', 'resource', 'protocol'], true) || ! $this->text($mcp->name ?? null) || ! $this->reference($mcp->entrypoint ?? null) || ! $this->strings($mcp->scopes ?? null)) {
                $this->findings[] = $label.' needs an MCP entrypoint, name, kind and explicit scopes';
            }
            $required = ['success', 'authorization', 'validation', 'privacy'];
            if (in_array($kind, ['write', 'external'], true)) {
                $required = [...$required, 'audit', 'retry', 'stale'];
            }
            if ($kind === 'external') {
                $required = [...$required, 'confirmation', 'uncertain_outcome'];
                if (! $this->text($mcp->confirmation ?? null)) {
                    $this->findings[] = $label.' needs an explicit consequential-action confirmation contract';
                }
            }
            if (($mcp->kind ?? null) === 'protocol') {
                $required = [...$required, 'handoff', 'status', 'no_bypass'];
                if (! $this->text($mcp->handoff ?? null)) {
                    $this->findings[] = $label.' needs a human handoff and authoritative readback contract';
                }
            }
            $this->tests($capability->tests ?? null, $label, $required);
            $variantIds[$name] = [];
            if (! is_array($capability->variants ?? null) || $capability->variants === []) {
                $this->findings[] = $label.' needs semantic UI/MCP variants, including a single default mode';

                continue;
            }
            foreach ($capability->variants as $index => $variant) {
                $variantLabel = $label.' variant '.($index + 1);
                if (! $variant instanceof stdClass) {
                    $this->findings[] = $variantLabel.' must be an object';

                    continue;
                }
                $identifier = $variant->id ?? null;
                if (! $this->identifier($identifier) || in_array($identifier, $variantIds[$name], true)) {
                    $this->findings[] = $variantLabel.' needs a unique stable id';

                    continue;
                }
                $variantIds[$name][] = $identifier;
                $uiContract = $this->contract($variant->ui ?? null);
                $mcpContract = $this->contract($variant->mcp ?? null);
                if (! ($variant->mcp ?? null) instanceof stdClass || ($variant->mcp->status ?? null) !== 'implemented') {
                    $this->findings[] = $variantLabel.' has a missing, partial or excluded MCP mode';
                }
                if ($uiContract === null || $mcpContract === null || $uiContract !== $mcpContract) {
                    $this->findings[] = $variantLabel.' must match normalized options, defaults and ordered effects';
                }
                $this->tests($variant->tests ?? null, $variantLabel, [...['success', 'error', 'recovery'], ...array_intersect($required, ['confirmation', 'uncertain_outcome', 'handoff', 'status', 'no_bypass'])]);
            }
        }
        $declared = $declaredModes = [];
        foreach ($inventory as $number => $entry) {
            $label = 'UI inventory entry '.($number + 1);
            if (! $entry instanceof stdClass || ! $this->sourcePath($entry->path ?? null) || ! $this->strings($entry->capabilities ?? null)) {
                $this->findings[] = $label.' needs an existing source path and capability ids';

                continue;
            }
            $path = $entry->path;
            if (isset($declared[$path])) {
                $this->findings[] = $label.' contains a duplicate path or capability id';
            }
            $declared[$path] = $entry->capabilities;
            $modes = $entry->variants ?? null;
            if (! $modes instanceof stdClass || ! $this->sameSet(array_keys(get_object_vars($modes)), $declared[$path])) {
                $this->findings[] = $label.' must enumerate variants for every UI capability';
                $modes = new stdClass;
            }
            foreach ($entry->capabilities as $name) {
                if (! in_array($name, $ids, true) || ! in_array($path, $uiPaths[$name] ?? [], true)) {
                    $this->findings[] = $label.' has an unmapped capability';
                }
                if (! $this->strings($modes->{$name} ?? null) || array_diff($modes->{$name}, $variantIds[$name] ?? []) !== []) {
                    $this->findings[] = $label.' has missing or unmapped semantic variants';
                } else {
                    $declaredModes[$name] = [...($declaredModes[$name] ?? []), ...$modes->{$name}];
                }
            }
        }
        foreach ($variantIds as $name => $variants) {
            if (! $this->sameSet($variants, $declaredModes[$name] ?? [])) {
                $this->findings[] = 'semantic variants are absent from the reviewed UI inventory';
            }
        }
        foreach ($uiPaths as $name => $paths) {
            foreach ($paths as $path) {
                if (! in_array($name, $declared[$path] ?? [], true)) {
                    $this->findings[] = 'a capability UI reference is absent from the reviewed inventory';
                    break;
                }
            }
        }
    }

    /** @param list<string> $required */
    private function tests(mixed $tests, string $label, array $required): void
    {
        $covered = [];
        if (! is_array($tests) || $tests === []) {
            $this->findings[] = $label.' needs parity contract test references';
        } else {
            foreach ($tests as $test) {
                if (! $test instanceof stdClass || ! $this->sourcePath($test->path ?? null, test: true) || ! $this->text($test->name ?? null) || ! $this->strings($test->covers ?? null) || array_diff($test->covers, self::CATEGORIES) !== []) {
                    $this->findings[] = $label.' has an invalid parity test reference';
                } else {
                    $covered = [...$covered, ...$test->covers];
                }
            }
        }
        $missing = array_diff($required, $covered);
        sort($missing);
        if ($missing !== []) {
            $this->findings[] = $label.' lacks contract coverage: '.implode(', ', $missing);
        }
    }

    private function reference(mixed $value): bool
    {
        return $value instanceof stdClass && $this->sourcePath($value->path ?? null) && $this->text($value->symbol ?? null);
    }

    private function sourcePath(mixed $value, bool $test = false): bool
    {
        if (! is_string($value) || ! $this->localFile($value)) {
            return false;
        }
        $parts = explode('/', $value);
        $extension = pathinfo($value, PATHINFO_EXTENSION);
        if ($parts[0] === 'vendor') {
            if ($test || count($parts) < 5 || ! in_array($parts[3], ['src', 'resources', 'routes', 'config'], true) || ! in_array($parts[1].'/'.$parts[2], $this->locked, true)) {
                return false;
            }
        } elseif (! in_array($parts[0], ['app', 'src', 'routes', 'resources', 'tests', 'config'], true)) {
            return false;
        }

        return (! $test || ($parts[0] === 'tests' && in_array($extension, ['php', 'py', 'js', 'ts'], true))) && in_array($extension, ['php', 'vue', 'ts', 'js', 'py', 'json'], true);
    }

    private function localFile(string $path): bool
    {
        if (str_contains($path, '\\') || str_contains($path, "\0")) {
            return false;
        }
        $candidate = rtrim($this->base, '/');
        foreach (explode('/', $path) as $part) {
            if ($part === '' || str_starts_with($part, '.')) {
                return false;
            }
            $candidate .= '/'.$part;
            if (is_link($candidate)) {
                return false;
            }
        }

        return is_file($candidate) && is_readable($candidate);
    }

    private function readJson(string $path): mixed
    {
        if (! $this->localFile($path)) {
            return null;
        }

        return json_decode((string) file_get_contents($this->base.'/'.$path), flags: JSON_THROW_ON_ERROR);
    }

    private function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function identifier(mixed $value): bool
    {
        return $this->text($value) && preg_match('/^[a-z][a-z0-9_.-]*$/D', $value) === 1;
    }

    /** @phpstan-assert-if-true non-empty-list<non-empty-string> $value */
    private function strings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value !== [] && count(array_filter($value, $this->text(...))) === count($value) && count(array_unique($value)) === count($value);
    }

    /** @param list<string> $left
     * @param  list<string>  $right
     */
    private function sameSet(array $left, array $right): bool
    {
        return array_diff($left, $right) === [] && array_diff($right, $left) === [];
    }

    private function contract(mixed $value): ?string
    {
        if (! $value instanceof stdClass || ! ($value->options ?? null) instanceof stdClass || ! ($value->defaults ?? null) instanceof stdClass || ! $this->strings($value->effects ?? null)) {
            return null;
        }

        return json_encode($this->canonical((object) ['options' => $value->options, 'defaults' => $value->defaults, 'effects' => $value->effects]), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields);

            return (object) array_map($this->canonical(...), $fields);
        }

        return is_array($value) ? array_map($this->canonical(...), $value) : $value;
    }
}

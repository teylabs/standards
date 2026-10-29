<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

/**
 * One package's adoption surfaces file, format 1 (tey/kit `resources/adoption/surfaces.schema.json`), validated by hand against that schema.
 * Anything the schema does not allow fails closed: an unknown format, field, group, signal kind or fact, a malformed condition, a missing
 * required field or a `new` surface without `since`.
 */
final readonly class Inventory
{
    public const FILE = 'resources/adoption/surfaces.json';

    public const GROUPS = ['layout', 'navigation', 'action', 'form', 'data', 'feedback', 'overlay', 'content', 'auth', 'account', 'workspace', 'access', 'mail', 'jobs', 'documents', 'activity', 'modules', 'platform'];

    /** Signal kind => [allowed fields => type], where type is `list` (of strings), `items` (a non-empty list of strings), `classes` (a non-empty list of FQCNs), `string`, `int`, `scope`, `requires` or `attributes`. */
    public const SIGNALS = [
        'rule' => ['rule' => 'string', 'scope' => 'scope'],
        'vue-root' => ['tags' => 'list', 'requires' => 'requires', 'scope' => 'scope'],
        'file-name' => ['globs' => 'list', 'except' => 'items', 'scope' => 'scope'],
        'import' => ['specifiers' => 'list', 'scope' => 'scope'],
        'template-tag' => ['tags' => 'list', 'min' => 'int', 'where' => 'attributes', 'except' => 'attributes', 'scope' => 'scope'],
        'mail-view' => ['without' => 'list', 'scope' => 'scope'],
        'php-class' => ['extends' => 'list', 'implements' => 'list', 'names' => 'list'],
        'class-reference' => ['classes' => 'classes', 'min' => 'int', 'scope' => 'scope'],
        'composer' => ['packages' => 'list'],
        'migration' => ['tables' => 'list'],
    ];

    private const REQUIRED_SIGNAL_FIELDS = ['rule' => ['rule'], 'vue-root' => ['tags'], 'file-name' => ['globs'], 'import' => ['specifiers'], 'template-tag' => ['tags'], 'mail-view' => ['without'], 'php-class' => [], 'class-reference' => ['classes'], 'composer' => ['packages'], 'migration' => ['tables']];

    private const SLUG = '/\A[a-z][a-z0-9]*([.-][a-z0-9]+)*\z/';

    /**
     * @param  array{composer: string, npm?: string, alias?: string}  $package
     * @param  array<string, array{description: string, computed: array<string, mixed>}>  $facts  id => definition
     * @param  list<array<string, mixed>>  $surfaces
     */
    private function __construct(public string $source, public string $origin, public array $package, public array $facts, public array $surfaces) {}

    public function composer(): string
    {
        return $this->package['composer'];
    }

    /** @throws AdoptionError */
    public static function load(string $path, string $origin): self
    {
        if (! is_file($path) || ! is_readable($path) || ($json = @file_get_contents($path)) === false) {
            throw new AdoptionError("Cannot read the adoption inventory {$path}.");
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new AdoptionError("The adoption inventory {$path} is not valid JSON: {$error->getMessage()}.");
        }

        return self::fromArray($data, $path, $origin);
    }

    /** @throws AdoptionError */
    public static function fromArray(mixed $data, string $source, string $origin = 'option'): self
    {
        $at = fn (string $where): string => "{$source}: {$where}";

        if (! is_array($data) || array_is_list($data)) {
            throw new AdoptionError($at('the file must be a JSON object'));
        }
        if (! array_key_exists('format', $data) || $data['format'] !== 1) {
            throw new AdoptionError($at('unknown format '.json_encode($data['format'] ?? null).'; this standards reads format 1 only'));
        }
        self::fields($data, ['format', 'package', 'facts', 'surfaces', 'nonSurfaces'], ['format', 'package', 'facts', 'surfaces', 'nonSurfaces'], $at('the file'));

        $package = self::object($data['package'], $at('package'));
        self::fields($package, ['composer', 'npm', 'alias'], ['composer'], $at('package'));
        foreach ($package as $key => $value) {
            if (! is_string($value) || $value === '') {
                throw new AdoptionError($at("package.{$key} must be a non-empty string"));
            }
        }
        if (preg_match('/\A[a-z0-9-]+\/[a-z0-9-]+\z/', $package['composer']) !== 1) {
            throw new AdoptionError($at('package.composer must be a vendor/name composer package'));
        }

        $facts = [];
        foreach (self::list($data['facts'], $at('facts')) as $index => $fact) {
            $fact = self::object($fact, $at("facts[{$index}]"));
            self::fields($fact, ['id', 'description', 'computed'], ['id', 'description', 'computed'], $at("facts[{$index}]"));
            self::slug($fact['id'], $at("facts[{$index}].id"));
            $where = $at("fact [{$fact['id']}]");
            self::string($fact['description'], "{$where}.description");
            if (isset($facts[$fact['id']])) {
                throw new AdoptionError("{$where}: duplicate fact");
            }
            $computed = self::object($fact['computed'], "{$where}.computed");
            if (count($computed) !== 1 || ! in_array(array_key_first($computed), ['analysis', 'signals'], true)) {
                throw new AdoptionError("{$where}.computed must be exactly one of {\"analysis\": <name>} or {\"signals\": [...]}");
            }
            if (isset($computed['analysis'])) {
                if (! is_string($computed['analysis']) || ! in_array($computed['analysis'], Facts::ANALYSES, true)) {
                    throw new AdoptionError("{$where}.computed: standards has no fact analysis ".json_encode($computed['analysis']).' (it implements '.implode(', ', Facts::ANALYSES).'); update tey/standards or rework the fact');
                }
            } else {
                $signals = self::list($computed['signals'], "{$where}.computed.signals");
                if ($signals === []) {
                    throw new AdoptionError("{$where}.computed.signals needs at least one signal");
                }
                foreach ($signals as $signalIndex => $signal) {
                    if (is_array($signal) && ($signal['kind'] ?? null) === 'rule') {
                        throw new AdoptionError("{$where}.computed.signals[{$signalIndex}]: a fact cannot be computed from a rule signal");
                    }
                    self::signal($signal, "{$where}.computed.signals[{$signalIndex}]");
                }
            }
            $facts[$fact['id']] = ['description' => $fact['description'], 'computed' => $computed];
        }

        $surfaces = [];
        $ids = [];
        foreach (self::list($data['surfaces'], $at('surfaces')) as $index => $surface) {
            $where = $at("surfaces[{$index}]");
            $surface = self::object($surface, $where);
            self::fields($surface, ['id', 'layer', 'group', 'label', 'feature', 'applies', 'uses', 'handRolled', 'replaces', 'docs', 'status', 'since'], ['id', 'layer', 'group', 'label', 'feature', 'applies', 'uses', 'handRolled', 'docs', 'status'], $where);
            self::slug($surface['id'], "{$where}.id");
            $where = $at("surface [{$surface['id']}]");
            if (isset($ids[$surface['id']])) {
                throw new AdoptionError("{$where}: duplicate surface id");
            }
            $ids[$surface['id']] = true;
            self::enum($surface['layer'], ['ui', 'backend'], "{$where}.layer");
            self::enum($surface['group'], self::GROUPS, "{$where}.group");
            foreach (['label', 'feature', 'docs'] as $field) {
                self::string($surface[$field], "{$where}.{$field}");
            }
            self::enum($surface['status'], ['stable', 'new'], "{$where}.status");
            if (array_key_exists('since', $surface)) {
                self::string($surface['since'], "{$where}.since");
            } elseif ($surface['status'] === 'new') {
                throw new AdoptionError("{$where}: a new surface needs `since`");
            }
            self::condition($surface['applies'], $facts, "{$where}.applies");

            $uses = self::object($surface['uses'], "{$where}.uses");
            if ($uses === []) {
                throw new AdoptionError("{$where}.uses needs at least one of imports, components, files, classes, config");
            }
            self::fields($uses, ['imports', 'components', 'files', 'classes', 'config'], [], "{$where}.uses");
            foreach ($uses as $key => $values) {
                self::strings($values, "{$where}.uses.{$key}");
            }
            foreach ($uses['imports'] ?? [] as $import) {
                if (preg_match('/\A(.+):([A-Za-z_$][\w$]*)\z/', $import) !== 1) {
                    throw new AdoptionError("{$where}.uses.imports: [{$import}] must be \"<alias>:Name\"");
                }
            }

            foreach (self::list($surface['handRolled'], "{$where}.handRolled") as $signalIndex => $signal) {
                self::signal($signal, "{$where}.handRolled[{$signalIndex}]");
            }

            if (array_key_exists('replaces', $surface)) {
                $replaces = self::object($surface['replaces'], "{$where}.replaces");
                self::fields($replaces, ['npm', 'composer', 'components'], [], "{$where}.replaces");
                foreach ($replaces as $key => $values) {
                    self::strings($values, "{$where}.replaces.{$key}");
                }
            }

            $surfaces[] = $surface;
        }

        foreach (self::list($data['nonSurfaces'], $at('nonSurfaces')) as $index => $entry) {
            $entry = self::object($entry, $at("nonSurfaces[{$index}]"));
            self::fields($entry, ['ref', 'reason'], ['ref', 'reason'], $at("nonSurfaces[{$index}]"));
            self::string($entry['ref'], $at("nonSurfaces[{$index}].ref"));
            self::string($entry['reason'], $at("nonSurfaces[{$index}].reason"));
        }

        /** @var array{composer: string, npm?: string, alias?: string} $package */
        return new self($source, $origin, $package, $facts, $surfaces);
    }

    /** @param array<string, mixed> $signal */
    private static function signal(mixed $signal, string $where): void
    {
        $signal = self::object($signal, $where);
        $kind = $signal['kind'] ?? null;
        if (! is_string($kind) || ! isset(self::SIGNALS[$kind])) {
            throw new AdoptionError("{$where}: unknown signal kind ".json_encode($kind).' (known: '.implode(', ', array_keys(self::SIGNALS)).')');
        }
        $allowed = self::SIGNALS[$kind];
        self::fields($signal, ['kind', ...array_keys($allowed)], ['kind', ...self::REQUIRED_SIGNAL_FIELDS[$kind]], "{$where} ({$kind})");
        foreach ($allowed as $field => $type) {
            if (! array_key_exists($field, $signal)) {
                continue;
            }
            $value = $signal[$field];
            match ($type) {
                'list' => self::strings($value, "{$where}.{$field}"),
                'items' => self::items($value, "{$where}.{$field}"),
                'classes' => self::classNames($value, "{$where}.{$field}"),
                'string' => self::string($value, "{$where}.{$field}"),
                'int' => is_int($value) && $value >= 1 ? null : throw new AdoptionError("{$where}.{$field} must be an integer of at least 1"),
                'scope' => self::enum($value, ['signed-in', 'public', 'any'], "{$where}.{$field}"),
                'requires' => self::requires($value, "{$where}.{$field}"),
                'attributes' => self::attributes($value, "{$where}.{$field}"),
            };
        }
        // An empty php-class signal would match every class: refuse it rather than guess.
        if ($kind === 'php-class' && array_filter([$signal['extends'] ?? [], $signal['implements'] ?? [], $signal['names'] ?? []]) === []) {
            throw new AdoptionError("{$where} (php-class) needs a non-empty extends, implements or names");
        }
        if ($kind === 'rule' && ! array_key_exists($signal['rule'], \Tey\Standards\Conformance::TESTS)) {
            throw new AdoptionError("{$where}: rule [{$signal['rule']}] is not a standards rule");
        }
    }

    /** A template-tag `where`/`except` filter: a non-empty object of attribute name => non-empty list of literal string values. */
    private static function attributes(mixed $value, string $where): void
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            throw new AdoptionError("{$where} must be a non-empty object of attribute name => list of values");
        }
        foreach ($value as $attribute => $values) {
            if (! is_string($attribute) || preg_match('/\A[a-z][a-z0-9-]*\z/', $attribute) !== 1) {
                throw new AdoptionError("{$where}: ".json_encode($attribute).' is not a static attribute name (lowercase, no `:`, `@` or `v-` prefix)');
            }
            if (! is_array($values) || ! array_is_list($values) || $values === []) {
                throw new AdoptionError("{$where}.{$attribute} must be a non-empty list of strings");
            }
            self::strings($values, "{$where}.{$attribute}");
        }
    }

    private static function requires(mixed $value, string $where): void
    {
        $value = self::object($value, $where);
        self::fields($value, ['routes', 'components', 'min'], [], $where);
        foreach (['routes', 'components'] as $field) {
            if (array_key_exists($field, $value)) {
                self::strings($value[$field], "{$where}.{$field}");
            }
        }
        if (array_key_exists('min', $value) && (! is_int($value['min']) || $value['min'] < 1)) {
            throw new AdoptionError("{$where}.min must be an integer of at least 1");
        }
    }

    /** @param array<string, mixed> $facts */
    private static function condition(mixed $condition, array $facts, string $where): void
    {
        if ($condition === true) {
            return;
        }
        if (is_string($condition)) {
            self::slug($condition, $where);
            if (! isset($facts[$condition])) {
                throw new AdoptionError("{$where}: unknown fact [{$condition}]; it is not in this file's `facts`");
            }

            return;
        }
        if (! is_array($condition) || array_is_list($condition) || count($condition) !== 1) {
            throw new AdoptionError("{$where}: a condition is true, a fact id, or an object with exactly one of all, any, not");
        }
        $operator = (string) array_key_first($condition);
        $operand = $condition[$operator];
        if ($operator === 'not') {
            self::condition($operand, $facts, "{$where}.not");

            return;
        }
        if (! in_array($operator, ['all', 'any'], true)) {
            throw new AdoptionError("{$where}: unknown condition operator [{$operator}]");
        }
        if (! is_array($operand) || ! array_is_list($operand) || $operand === []) {
            throw new AdoptionError("{$where}.{$operator} must be a non-empty list of conditions");
        }
        foreach ($operand as $index => $inner) {
            self::condition($inner, $facts, "{$where}.{$operator}[{$index}]");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private static function fields(array $data, array $allowed, array $required, string $where): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new AdoptionError("{$where}: unknown field [{$key}]");
            }
        }
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                throw new AdoptionError("{$where}: missing required field [{$key}]");
            }
        }
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $where): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new AdoptionError("{$where} must be an object");
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $where): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new AdoptionError("{$where} must be a list");
        }

        return $value;
    }

    private static function strings(mixed $value, string $where): void
    {
        foreach (self::list($value, $where) as $item) {
            if (! is_string($item) || $item === '') {
                throw new AdoptionError("{$where} must be a list of non-empty strings");
            }
        }
    }

    private static function items(mixed $value, string $where): void
    {
        if ($value === []) {
            throw new AdoptionError("{$where} must be a non-empty list of strings");
        }
        self::strings($value, $where);
    }

    /** Fully qualified class names, a leading `\` optional: `Mpdf\Mpdf`, `\Barryvdh\DomPDF\Facade\Pdf`. */
    private static function classNames(mixed $value, string $where): void
    {
        self::items($value, $where);
        foreach ($value as $class) {
            if (preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $class) !== 1) {
                throw new AdoptionError("{$where}: ".json_encode($class).' is not a fully qualified class name');
            }
        }
    }

    private static function string(mixed $value, string $where): void
    {
        if (! is_string($value) || $value === '') {
            throw new AdoptionError("{$where} must be a non-empty string");
        }
    }

    /** @param list<string> $values */
    private static function enum(mixed $value, array $values, string $where): void
    {
        if (! is_string($value) || ! in_array($value, $values, true)) {
            throw new AdoptionError("{$where}: ".json_encode($value).' is not one of '.implode(', ', $values));
        }
    }

    private static function slug(mixed $value, string $where): void
    {
        if (! is_string($value) || preg_match(self::SLUG, $value) !== 1) {
            throw new AdoptionError("{$where}: ".json_encode($value).' is not a lowercase dotted id');
        }
    }
}

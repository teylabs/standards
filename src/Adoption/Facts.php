<?php

declare(strict_types=1);

namespace Tey\Standards\Adoption;

/**
 * Computes the app facts an inventory's `applies` names, from each fact's `computed` block (format 1): either one of standards' own route and
 * model analyses (ANALYSES), or true when any of its signals fires in an app-owned file. Analyses read static evidence in the app's own
 * files (kit-installed files count: they are the app's pages and routes; vendor code never does). Conservative: a fact is true only on
 * evidence, so an app may read a surface as n/a that a person would call relevant (a blind spot), never the reverse. Each true fact carries
 * the first evidence found.
 */
final class Facts
{
    /** The closed list of analyses a fact's `computed.analysis` may name; any other name fails closed (Inventory). */
    public const ANALYSES = [
        'signed-in-pages', 'public-pages', 'auth-routes', 'workspace-model', 'mail-notifications', 'mcp-server', 'settings-routes',
        'search-route', 'destructive-routes',
    ];

    private const AUTH_PACKAGES = ['laravel/fortify', 'laravel/breeze', 'laravel/ui', 'laravel/jetstream'];

    private const AUTH_URIS = '~^/?(?:login|logout|register|forgot-password|reset-password|password|email/verify|verify-email|two-factor|confirm-password|user/confirm-password)~';

    /** Code that renders a page: Inertia::render, inertia(), view(), Route::inertia or Route::view. */
    private const RENDERS = '~\bInertia::render\s*\(|\binertia\s*\(|\bview\s*\(~';

    /** An API route group: the `api` middleware (alone or with others) or an `api` URI prefix. */
    private const API = '~[\'"]api[\'"]|\bprefix\s*\(\s*[\'"]/?api(?:/|[\'"])~';

    /** Auth middleware in a route statement or group: `'auth'`, `'auth:<guard>'` or Authenticate::class. */
    private const AUTH = '~[\'"]auth(?::[\w,-]+)?[\'"]|\bAuthenticate::class~';

    /** @var array<string, array{value: bool, evidence: ?string}> */
    private array $values = [];

    /** @var ?array{into: array<string, list<array{from: string, line: int, context: string}>>, blind: list<string>} */
    private ?array $includes = null;

    /** @var ?array<string, list<string>> */
    private ?array $viewScopes = null;

    /** @param  ?\Closure(array<string, mixed>): list<array{file: string, line: ?int, detail: string}>  $signals  fires one signal (the Scanner's evaluator) */
    public function __construct(private readonly App $app, private readonly ?\Closure $signals = null) {}

    /**
     * @param  array{analysis?: string, signals?: list<array<string, mixed>>}  $computed  a fact's validated `computed` block
     * @return array{value: bool, evidence: ?string}
     */
    public function compute(array $computed): array
    {
        $key = json_encode($computed, JSON_THROW_ON_ERROR);

        return $this->values[$key] ??= isset($computed['analysis']) ? $this->analysis($computed['analysis']) : $this->fromSignals($computed['signals'] ?? []);
    }

    /**
     * Evaluates an `applies` condition (validated by Inventory) against one inventory's fact definitions.
     *
     * @param  array<string, array{description: string, computed: array<string, mixed>}>  $facts
     */
    public function holds(mixed $condition, array $facts): bool
    {
        if ($condition === true) {
            return true;
        }
        if (is_string($condition)) {
            return $this->compute($facts[$condition]['computed'])['value'];
        }
        $operator = (string) array_key_first($condition);

        return match ($operator) {
            'not' => ! $this->holds($condition['not'], $facts),
            'all' => array_reduce($condition['all'], fn (bool $carry, mixed $inner): bool => $carry && $this->holds($inner, $facts), true),
            'any' => array_reduce($condition['any'], fn (bool $carry, mixed $inner): bool => $carry || $this->holds($inner, $facts), false),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $signals
     * @return array{value: bool, evidence: ?string}
     */
    private function fromSignals(array $signals): array
    {
        if ($this->signals === null) {
            throw new AdoptionError('A signal-computed fact needs the scanner\'s signal evaluator.');
        }
        foreach ($signals as $signal) {
            $fired = ($this->signals)($signal);
            if ($fired !== []) {
                return ['value' => true, 'evidence' => $fired[0]['file'].($fired[0]['line'] === null ? '' : ':'.$fired[0]['line']).' '.$fired[0]['detail']];
            }
        }

        return ['value' => false, 'evidence' => null];
    }

    /** @return array{value: bool, evidence: ?string} */
    public function analysis(string $name): array
    {
        $evidence = match ($name) {
            // A route statement or group behind the auth middleware (`'auth'`, `'auth:<guard>'`, Authenticate::class) that renders a page:
            // Inertia::render, inertia(), view(), Route::inertia/Route::view, or an app controller whose class renders one. API routes
            // (routes/api.php, an `api` middleware group or an `api` prefix) never count.
            'signed-in-pages' => $this->signedInPage(),
            // A top-level GET, view or inertia route in a route file outside any group, with no auth middleware in its statement and
            // not an auth screen's URI; or a page whose path names a public surface other than sign-in (Public, Marketing, Landing, Welcome, Legal, Docs).
            'public-pages' => $this->publicRoute() ?? $this->firstPath($this->app->frontend(), fn (string $path): bool => $this->app->page($path) && preg_match('~/(?:[Pp]ublic|[Mm]arketing|[Ll]anding|[Ww]elcome|[Ll]egal|[Dd]ocs)(?![a-z])~', $path) === 1),
            // An auth package that registers the auth routes (fortify, breeze, ui, jetstream) in composer.json, or a login,
            // password-reset or email-verification route in a route file.
            'auth-routes' => $this->composer(self::AUTH_PACKAGES)
                ?? $this->firstMatch($this->app->routes(), '~[\'"](?:/?login|/?forgot-password|password\.request|password\.reset|verification\.notice|/?email/verify)[\'"]~'),
            // An app class named Workspace, Team or Organisation/Organization, or a migration that creates such a table.
            'workspace-model' => $this->className(['Workspace', 'Team', 'Organisation', 'Organization']) ?? $this->createsTable(['workspaces', 'teams', 'organisations', 'organizations']),
            // An app class extends Illuminate\Mail\Mailable, or defines toMail() (a notification's mail channel).
            'mail-notifications' => $this->extends('Illuminate\\Mail\\Mailable') ?? $this->firstMatch($this->phpApp(), '~\bfunction\s+toMail\s*\(~'),
            // laravel/mcp in composer.json, routes/ai.php, or an Mcp::web()/Mcp::local() registration.
            'mcp-server' => $this->composer(['laravel/mcp']) ?? ($this->app->exists('routes/ai.php') ? 'routes/ai.php' : null) ?? $this->firstMatch($this->app->routes(), '~\bMcp::(?:web|local)\s*\(~'),
            // A route URI containing `settings` in a route file.
            'settings-routes' => $this->firstMatch($this->app->routes(), '~[\'"]/?[\w/{}-]*settings[\w/{}-]*[\'"]~'),
            // A route URI or name containing `search` in a route file.
            'search-route' => $this->firstMatch($this->app->routes(), '~[\'"][\w./{}-]*search[\w./{}-]*[\'"]~i'),
            // A route file registers a DELETE route or a resource controller (whose destroy route deletes).
            'destructive-routes' => $this->firstMatch($this->app->routes(), '~\bRoute::(?:delete|resource|apiResource|resources)\s*\(|->delete\s*\(\s*[\'"]~'),
            default => throw new AdoptionError("standards has no fact analysis [{$name}]."),
        };

        return ['value' => $evidence !== null, 'evidence' => $evidence];
    }

    /** @return list<string> app PHP outside routes, migrations and config */
    private function phpApp(): array
    {
        return array_values(array_filter($this->app->php(), fn (string $path): bool => ! preg_match('~^(?:routes|database/migrations|config)/~', $path)));
    }

    /** @param list<string> $files */
    private function firstMatch(array $files, string $pattern): ?string
    {
        foreach ($files as $path) {
            $source = $this->app->source($path);
            if (preg_match($pattern, Code::withoutComments($source), $match, PREG_OFFSET_CAPTURE) === 1) {
                return $path.':'.App::line($source, $match[0][1]);
            }
        }

        return null;
    }

    /** @param list<string> $files */
    private function firstPath(array $files, callable $test): ?string
    {
        foreach ($files as $path) {
            if ($test($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @param list<string> $packages */
    private function composer(array $packages): ?string
    {
        foreach ($packages as $package) {
            if (array_key_exists($package, $this->app->composerPackages())) {
                return 'composer.json: '.$package;
            }
        }

        return null;
    }

    /** @param list<string> $tables */
    private function createsTable(array $tables): ?string
    {
        foreach ($this->app->migrations() as $path) {
            $source = $this->app->source($path);
            foreach (Code::createdTables($source) as $created) {
                if (in_array($created['table'], $tables, true)) {
                    return $path.':'.App::line($source, $created['offset']);
                }
            }
        }

        return null;
    }

    /** @param list<string> $names */
    private function className(array $names): ?string
    {
        foreach ($this->phpApp() as $path) {
            $source = $this->app->source($path);
            foreach (Code::classes($source) as $class) {
                if (in_array($class['name'], $names, true)) {
                    return $path.':'.App::line($source, $class['offset']);
                }
            }
        }

        return null;
    }

    private function extends(string $parent): ?string
    {
        foreach ($this->phpApp() as $path) {
            $source = $this->app->source($path);
            foreach (Code::classes($source) as $class) {
                if ($class['extends'] === $parent) {
                    return $path.':'.App::line($source, $class['offset']);
                }
            }
        }

        return null;
    }

    /** The first auth-middleware route statement or group, outside the API, that renders a page directly or through an app controller. */
    private function signedInPage(): ?string
    {
        foreach ($this->app->routes() as $path) {
            if (basename($path) === 'api.php') {
                continue;
            }
            $source = $this->app->source($path);
            $code = Code::withoutComments($source);
            foreach ($this->contexts($path) as $context) {
                if (preg_match(self::API, $context['middleware']) === 1) {
                    continue;
                }
                // The whole file inherits an auth group it is included from: any page it renders is signed in.
                if (preg_match(self::AUTH, $context['middleware']) === 1 && (preg_match(self::RENDERS, $code) === 1 || $this->rendersThroughController($code))) {
                    return $context['via'].' includes '.$path;
                }
                preg_match_all(self::AUTH, $code, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as [, $offset]) {
                    $statement = self::statement($code, $offset);
                    if (preg_match(self::API, $statement) === 1) {
                        continue;
                    }
                    if (preg_match(self::RENDERS, $statement) === 1 || $this->rendersThroughController($statement)) {
                        return $path.':'.App::line($source, $offset);
                    }
                }
            }
        }

        return null;
    }

    /**
     * The scopes each routed Blade view reads, by its `resources/views/...blade.php` path. A view is `signed-in` when a route that
     * passes the signed-in-pages reading (auth middleware on its statement, an enclosing group or an including group; never an API
     * route) renders it, and `public` when a route that passes the public-pages reading does (top-level, no middleware, not an auth
     * screen's URI, in a file no middleware group includes). A route renders a view through `Route::view('<uri>', '<view>')`, a
     * literal `view('<view>')` or `View::make('<view>')` in its closure, its controller action (`[Name::class, 'method']`, or
     * `__invoke` for `Name::class`), or a Livewire full-page component (`Name::class`) whose `render()` names one. A class is found
     * by its short name and only when exactly one app file has it. Only the routed view itself is scoped: a layout, partial or
     * component it pulls in is not followed, so it reads `unknown` (absent here).
     *
     * @return array<string, list<string>>
     */
    public function viewScopes(): array
    {
        if ($this->viewScopes !== null) {
            return $this->viewScopes;
        }
        $scopes = [];
        foreach ($this->app->routes() as $path) {
            if (basename($path) === 'api.php') {
                continue;
            }
            $code = Code::withoutComments($this->app->source($path));
            $contexts = $this->contexts($path);
            preg_match_all('~(?:\bRoute::|->)\s*(get|post|put|patch|delete|options|any|match|view)\s*\(~i', $code, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $index => [$call, $offset]) {
                $statement = self::statement($code, $offset);
                $views = $this->renderedViews($statement, strtolower($matches[1][$index][0]) === 'view');
                if ($views === []) {
                    continue;
                }
                $headers = self::enclosingHeaders($code, $offset);
                $uri = preg_match('~\G\s*([\'"])([^\'"]*)\1~', $code, $literal, 0, $offset + strlen($call)) === 1 ? $literal[2] : null;
                foreach ($contexts as $context) {
                    $around = $context['middleware'].' '.$headers.' '.$statement;
                    if (preg_match(self::API, $around) === 1) {
                        continue;
                    }
                    $scope = match (true) {
                        preg_match(self::AUTH, $around) === 1 => 'signed-in',
                        trim($headers) === '' && $uri !== null && preg_match(self::AUTH_URIS, $uri) !== 1 && ! str_contains($context['middleware'], 'auth')
                            && ! str_contains($context['middleware'], 'middleware') && ! str_contains($statement, 'auth') && ! str_contains($statement, 'middleware(') => 'public',
                        default => null,
                    };
                    foreach ($scope === null ? [] : $views as $view) {
                        $scopes[$view][$scope] = $scope;
                    }
                }
            }
        }

        return $this->viewScopes = array_map(fn (array $set): array => array_values($set), $scopes);
    }

    /**
     * The scope a hit in an `app/Filament/**` file reads: `signed-in` when every panel provider (`app/Providers/Filament/*PanelProvider.php`)
     * that discovers the file's folder (`discoverResources`/`Pages`/`Widgets`/`Clusters` with a literal `app_path('...')`) calls
     * `->login()` or passes a non-empty literal array to `->authMiddleware([...])`; else, or when no provider claims the folder, `unknown`
     * (an empty list). A lone provider that discovers nothing claims every app/Filament file.
     *
     * @return list<string>
     */
    public function filamentScopes(string $path): array
    {
        $providers = array_values(array_filter($this->app->php(), fn (string $file): bool => preg_match('~^app/Providers/Filament/[^/]*PanelProvider\.php$~', $file) === 1));
        $panels = [];
        foreach ($providers as $provider) {
            $code = Code::withoutComments($this->app->source($provider));
            preg_match_all('~->\s*discover(?:Resources|Pages|Widgets|Clusters)\s*\(\s*(?:in\s*:\s*)?\\\\?app_path\s*\(\s*([\'"])([^\'"$]+)\1\s*\)~', $code, $found);
            $panels[] = [
                'folders' => array_map(fn (string $folder): string => 'app/'.trim($folder, '/').'/', $found[2]),
                'signed' => preg_match('~->\s*login\s*\(|->\s*authMiddleware\s*\(\s*\[\s*[^\s\]]~', $code) === 1,
            ];
        }
        $claiming = array_values(array_filter($panels, fn (array $panel): bool => array_filter($panel['folders'], fn (string $folder): bool => str_starts_with($path, $folder)) !== []));
        if ($claiming === [] && count($panels) === 1 && $panels[0]['folders'] === []) {
            $claiming = $panels;
        }

        return $claiming !== [] && array_filter($claiming, fn (array $panel): bool => ! $panel['signed']) === [] ? ['signed-in'] : [];
    }

    /**
     * The Blade view paths a route statement renders (see viewScopes).
     *
     * @return list<string>
     */
    private function renderedViews(string $statement, bool $viewRoute): array
    {
        $names = [];
        if ($viewRoute && preg_match('~\bview\s*\(\s*([\'"])[^\'"]*\1\s*,\s*([\'"])([\w.-]+)\2~', $statement, $match) === 1) {
            $names[] = $match[3];
        }
        $literal = '~(?:\bview|\bView::make)\s*\(\s*([\'"])([\w.-]+)\1~';
        preg_match_all($literal, $statement, $inline);
        array_push($names, ...$inline[2]);
        preg_match_all('~\b([A-Z]\w*)::class\b(\s*,\s*([\'"])(\w+)\3)?~', $statement, $classes, PREG_SET_ORDER);
        foreach ($classes as $class) {
            if (in_array($class[1], ['Route', 'Authenticate'], true)) {
                continue;
            }
            $files = array_values(array_filter($this->phpApp(), fn (string $path): bool => basename($path, '.php') === $class[1]));
            if (count($files) !== 1) {
                continue;
            }
            $code = Code::withoutComments($this->app->source($files[0]));
            foreach (isset($class[4]) && $class[4] !== '' ? [$class[4]] : ['__invoke', 'render'] as $method) {
                preg_match_all($literal, self::method($code, $method), $rendered);
                array_push($names, ...$rendered[2]);
            }
        }

        return array_values(array_unique(array_map(fn (string $name): string => 'resources/views/'.str_replace('.', '/', $name).'.blade.php', $names)));
    }

    /** The body of a class method (from `function <name>(` to its closing brace), or '' when there is none. */
    private static function method(string $code, string $name): string
    {
        if (preg_match('~\bfunction\s+'.preg_quote($name, '~').'\s*\(~i', $code, $match, PREG_OFFSET_CAPTURE) !== 1 || ($open = strpos($code, '{', $match[0][1])) === false) {
            return '';
        }
        $depth = 0;
        for ($i = $open, $length = strlen($code); $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}' && --$depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }

        return substr($code, $open);
    }

    /**
     * Route-file includes that could not be resolved to a literal app file (a variable, a call, a missing file or a path outside the
     * app), as `file:line` notes. Their routes are not read, so a fact they would make true reads false: a blind spot, never a guess.
     *
     * @return list<string>
     */
    public function blindSpots(): array
    {
        return $this->includes()['blind'];
    }

    /**
     * The group contexts a route file's routes run in: one per chain of literal includes that reaches it from a route file nothing
     * includes, each with the middleware and prefix text of every enclosing group along the chain and the first include site
     * (`via`). A route file nothing includes has one empty context. A chain that loops back on itself is dropped.
     *
     * @param  list<string>  $seen
     * @return list<array{middleware: string, via: string}>
     */
    private function contexts(string $path, array $seen = []): array
    {
        $includes = $this->includes()['into'][$path] ?? [];
        if ($includes === []) {
            return [['middleware' => '', 'via' => '']];
        }
        $contexts = [];
        foreach ($includes as $include) {
            if (in_array($include['from'], [...$seen, $path], true)) {
                continue;
            }
            foreach ($this->contexts($include['from'], [...$seen, $path]) as $outer) {
                $contexts[] = ['middleware' => trim($outer['middleware'].' '.$include['context']), 'via' => $outer['via'] !== '' ? $outer['via'] : $include['from'].':'.$include['line']];
            }
        }

        return $contexts;
    }

    /**
     * Every `require`/`include` (and `_once`) and `->group(<file>)` in a route file, resolved when its path is literal:
     * `__DIR__.'/x.php'`, `base_path('x.php')` or a quoted string (app-relative, else relative to the including file). Each
     * records the header text of its enclosing groups (and of its own `->group()` statement), which carry their middleware and prefix.
     *
     * @return array{into: array<string, list<array{from: string, line: int, context: string}>>, blind: list<string>}
     */
    private function includes(): array
    {
        if ($this->includes !== null) {
            return $this->includes;
        }
        $into = [];
        $blind = [];
        foreach ($this->app->routes() as $path) {
            $source = $this->app->source($path);
            $code = Code::withoutComments($source);
            preg_match_all('~\b(?:require|include)(?:_once)?\b\s*(?=[^;]*;)|->\s*group\s*\(\s*(?!function\b|fn\b|static\b|\[|\$\w+\s*=>)~i', $code, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$keyword, $offset]) {
                $grouped = str_starts_with($keyword, '->');
                $from = $offset + strlen($keyword);
                $expression = $grouped ? self::argument($code, $from) : trim(substr($code, $from, (int) strpos($code, ';', $from) - $from));
                $line = App::line($source, $offset);
                $target = $this->resolveInclude($path, $expression);
                if ($target === null) {
                    $blind[] = $path.':'.$line.' includes a route file standards cannot resolve ('.preg_replace('~\s+~', ' ', $expression).'); its routes are not read';

                    continue;
                }
                $context = self::enclosingHeaders($code, $offset).($grouped ? ' '.substr($code, self::bounds($code, $offset)[0], $offset - self::bounds($code, $offset)[0]) : '');
                $into[$target][] = ['from' => $path, 'line' => $line, 'context' => trim($context)];
            }
        }

        return $this->includes = ['into' => $into, 'blind' => $blind];
    }

    /** The app-relative route file a literal include expression names, or null when it is not literal or names no app route file. */
    private function resolveInclude(string $from, string $expression): ?string
    {
        $expression = trim($expression);
        while (preg_match('~^\((.*)\)$~s', $expression, $inner) === 1) {
            $expression = trim($inner[1]);
        }
        $literal = '([\'"])([^\'"$\\\\]+)\1';
        $candidates = match (true) {
            preg_match('~^__DIR__\s*\.\s*'.$literal.'$~', $expression, $m) === 1 => [dirname($from).'/'.$m[2]],
            preg_match('~^(?:\\\\?base_path)\s*\(\s*'.$literal.'\s*\)$~', $expression, $m) === 1 => [$m[2]],
            preg_match('~^'.$literal.'$~', $expression, $m) === 1 => [$m[2], dirname($from).'/'.$m[2]],
            default => [],
        };
        foreach ($candidates as $candidate) {
            $parts = [];
            foreach (explode('/', str_replace('\\', '/', $candidate)) as $part) {
                if ($part === '' || $part === '.') {
                    continue;
                }
                if ($part === '..') {
                    if (array_pop($parts) === null) {
                        continue 2;
                    }

                    continue;
                }
                $parts[] = $part;
            }
            $relative = implode('/', $parts);
            if ($relative !== '' && in_array($relative, $this->app->routes(), true)) {
                return $relative;
            }
        }

        return null;
    }

    /** The argument of a call starting at $offset, up to its closing parenthesis. */
    private static function argument(string $code, int $offset): string
    {
        $depth = 0;
        for ($i = $offset, $length = strlen($code); $i < $length; $i++) {
            $char = $code[$i];
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    return substr($code, $offset, $i - $offset);
                }
                $depth--;
            }
        }

        return substr($code, $offset);
    }

    /** The header (from its statement start to the `{`) of every group body enclosing $offset, outermost first. */
    private static function enclosingHeaders(string $code, int $offset): string
    {
        $open = [];
        for ($i = 0; $i < $offset; $i++) {
            if ($code[$i] === '{') {
                $open[] = $i;
            } elseif ($code[$i] === '}') {
                array_pop($open);
            }
        }
        $headers = [];
        foreach ($open as $brace) {
            $start = self::bounds($code, $brace)[0];
            $headers[] = substr($code, $start, $brace - $start);
        }

        return implode(' ', $headers);
    }

    /** Whether the statement names an app controller (`Name::class`) whose class file renders a page. */
    private function rendersThroughController(string $statement): bool
    {
        preg_match_all('~\b([A-Z]\w*)::class\b~', $statement, $classes);
        $names = array_diff(array_unique($classes[1]), ['Route', 'Authenticate']);
        if ($names === []) {
            return false;
        }
        foreach ($this->phpApp() as $path) {
            if (in_array(basename($path, '.php'), $names, true) && preg_match(self::RENDERS, Code::withoutComments($this->app->source($path))) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole PHP statement around $offset: back to the `;` or opening `{` that starts it and forward to its closing `;`, skipping
     * nested brackets, so a group closure is read in full and a chained `->middleware('auth')` sees the route before it.
     */
    private static function statement(string $code, int $offset): string
    {
        [$start, $end] = self::bounds($code, $offset);

        return substr($code, $start, $end - $start);
    }

    /** @return array{int, int} the start and end offsets of the statement around $offset (see statement()) */
    private static function bounds(string $code, int $offset): array
    {
        $start = 0;
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $code[$i];
            if ($char === ')' || $char === '}' || $char === ']') {
                $depth++;
            } elseif ($char === '(' || $char === '{' || $char === '[') {
                if ($depth === 0) {
                    if ($char === '{') {
                        $start = $i + 1;
                        break;
                    }

                    continue;
                }
                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                $start = $i + 1;
                break;
            }
        }
        $end = strlen($code);
        $depth = 0;
        for ($i = $offset, $length = strlen($code); $i < $length; $i++) {
            $char = $code[$i];
            if ($char === '(' || $char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === '}' || $char === ']') {
                if ($depth === 0 && $char === '}') {
                    $end = $i;
                    break;
                }
                $depth = max(0, $depth - 1);
            } elseif ($char === ';' && $depth === 0) {
                $end = $i;
                break;
            }
        }

        return [$start, $end];
    }

    /** A top-level (ungrouped) GET/view/inertia route statement with no `auth` in it and not an auth screen's URI. */
    private function publicRoute(): ?string
    {
        foreach ($this->app->routes() as $path) {
            // A file included from a group with middleware (auth, or any other) inherits it: its routes are not top-level public routes.
            foreach ($this->contexts($path) as $context) {
                if (str_contains($context['middleware'], 'auth') || str_contains($context['middleware'], 'middleware')) {
                    continue 2;
                }
            }
            $source = $this->app->source($path);
            $code = Code::withoutComments($source);
            preg_match_all('~\bRoute::(?:get|view|inertia)\s*\(\s*([\'"])([^\'"]*)\1~', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $offset = $match[0][1];
                $before = substr($code, 0, $offset);
                if (substr_count($before, '{') - substr_count($before, '}') !== 0 || preg_match(self::AUTH_URIS, $match[2][0]) === 1) {
                    continue;
                }
                $end = strpos($code, ';', $offset);
                $statement = substr($code, $offset, $end === false ? null : $end - $offset);
                $start = strrpos($before, ';');
                $prefix = substr($before, $start === false ? 0 : $start + 1);
                if (! str_contains($statement.$prefix, 'auth') && ! str_contains($statement.$prefix, 'middleware(')) {
                    return $path.':'.App::line($source, $offset);
                }
            }
        }

        return null;
    }
}

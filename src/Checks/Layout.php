<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Patterns\KitExports;

/**
 * The kit layout rules (release A, rhythm): Page gaps its children, components carry no outer margins, and spacing, columns, action groups and row
 * actions come from the kit's primitives. Each finding names the primitive to use, so it is a pointer as much as a refusal. Static: each app `.vue`
 * file's template is read as an element tree (VueTemplate); files tey/kit owns are not scanned. All advisory until an app lists the rule in
 * Configuration::$optionalRules, then fail-on-new against the allowlist baseline (`standards:allowlist --baseline`), which only shrinks.
 *
 * Stack and BlockStack (its name before tey/kit's rename) are read as the same primitive throughout.
 */
final class Layout
{
    public const ACTION_GROUP = 'ui.action-group';

    public const ROW_ACTIONS = 'ui.row-actions';

    public const PAGE_TITLE = 'ui.page-title-dup';

    public const OUTER_MARGINS = 'ui.no-outer-margins';

    public const PAGE_GRID = 'ui.page-grid';

    public const PRIMITIVES = 'ui.primitive-pointers';

    public const ROW_ACTION_LABEL = 'ui.row-action-label';

    public const RULES = [self::ACTION_GROUP, self::ROW_ACTIONS, self::PAGE_TITLE, self::OUTER_MARGINS, self::PAGE_GRID, self::PRIMITIVES, self::ROW_ACTION_LABEL];

    /** A row action label longer than this (characters or words) is clamped by the kit's RowActions. */
    public const LABEL_MAX_CHARACTERS = 24;

    public const LABEL_MAX_WORDS = 3;

    public const STACKS = ['Stack', 'BlockStack'];

    /**
     * Where adjacent action controls are already grouped. `Toolbar` holds mixed controls (a search box and buttons) and counts, as do the kit's *Toolbar
     * variants. The kit's `ActionGroup` counts too, unless the file imports that name from outside the kit (kitActionGroups).
     */
    private const ACTION_CONTAINERS = ['ButtonGroup', 'ActionBar', 'HeaderActions', 'Toolbar', 'FormActions'];

    /** Elements a Page lays out of flow: they take no gap and don't count against `flush`. */
    private const OUT_OF_FLOW = ['Teleport', 'Dialog', 'Modal', 'Sheet', 'AlertDialog', 'Drawer'];

    private const MARGIN = '~^-?m[tby]-|^-m[lrxse]?-|^space-y-~';

    /** @var array<string, string>|null */
    private ?array $files = null;

    public function __construct(private readonly Configuration $config) {}

    /** @return list<array{target: string, check: string, line?: int, advisory?: bool}> */
    public function violations(string $rule): array
    {
        if ($rule === self::ROW_ACTION_LABEL) {
            return $this->rowActionLabels();
        }
        $violations = [];
        foreach ($this->files() as $path => $source) {
            $template = VueTemplate::parse($source);
            if ($template === null && $rule !== self::PRIMITIVES) {
                continue;
            }
            array_push($violations, ...match ($rule) {
                self::ACTION_GROUP => $this->actionGroups($path, $source, $template),
                self::ROW_ACTIONS => $this->rowActions($path, $template),
                self::PAGE_TITLE => $this->pageTitles($path, $template),
                self::OUTER_MARGINS => $this->outerMargins($path, $source, $template),
                self::PAGE_GRID => $this->pageGrids($path, $template),
                self::PRIMITIVES => $this->primitives($path, $template),
                default => [],
            });
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }

    /** @return array<string, string> the app's own `.vue` files */
    private function files(): array
    {
        return $this->files ??= array_filter((new FrontendSource($this->config))->appFiles(), fn (string $path): bool => str_ends_with($path, '.vue'), ARRAY_FILTER_USE_KEY);
    }

    private function finding(string $path, VueTemplate $node, string $check, bool $advisory = false): array
    {
        return ['target' => $path, 'check' => $check, 'line' => $node->line, ...($advisory ? ['advisory' => true] : [])];
    }

    /** @return list<VueTemplate> */
    private static function pages(VueTemplate $template): array
    {
        return array_values(array_filter($template->descendants(), fn (VueTemplate $node): bool => $node->tag === 'Page'));
    }

    // ui.action-group

    private static function isAction(VueTemplate $node): bool
    {
        return in_array($node->tag, ['Button', 'button', 'DropdownConfirm'], true)
            || ($node->tag === 'Link' && ($node->attribute('as') === 'button' || $node->has('variant') || $node->has(':variant')));
    }

    /**
     * The tags that are tey/kit's `ActionGroup` (0.21) in the file: `ActionGroup` itself unless the file imports that name from elsewhere (a
     * globally registered or auto-imported component), and a name bound by a named import from `@kit/ui` or `@/components/Kit` (the barrel or
     * any module under them), renamed or not, or by a default import of its `ActionGroup.vue`.
     *
     * @return list<string>
     */
    private static function kitActionGroups(string $source): array
    {
        $imports = VueImports::imports(VueImports::code(VueImports::scripts($source)));
        $tags = isset($imports['ActionGroup']) ? [] : ['ActionGroup'];
        foreach ($imports as $local => [$from, $imported]) {
            $kit = preg_match('~^(?:@kit/ui|@/components/Kit)(?:/|$)~', $from) === 1;
            if ($kit && ($imported === 'ActionGroup' || ($imported === 'default' && preg_match('~/ActionGroup(?:\.vue)?$~', $from) === 1))) {
                $tags[] = $local;
            }
        }

        return $tags;
    }

    /**
     * In (or under) an action container, a *Toolbar, the kit's ActionGroup (either direction, any class), or a Page or SectionHeader #actions slot.
     *
     * @param  list<string>  $actionGroups  the tags the file binds to the kit's ActionGroup
     */
    private static function grouped(VueTemplate $node, array $actionGroups): bool
    {
        for (; $node !== null; $node = $node->parent) {
            $slot = $node->slot();
            if ($slot === 'actions' && in_array($node->parent?->tag, ['Page', 'SectionHeader'], true)) {
                return true;
            }
            if ($node->tag !== 'template' && (in_array($node->tag, [...self::ACTION_CONTAINERS, ...$actionGroups], true) || str_ends_with($node->tag, 'Toolbar'))) {
                return true;
            }
        }

        return false;
    }

    private function actionGroups(string $path, string $source, VueTemplate $template): array
    {
        $actionGroups = self::kitActionGroups($source);
        $found = [];
        foreach ([$template, ...$template->descendants()] as $container) {
            $slot = $container->slot();
            // A plain <template v-if>/<template v-for> is looked through by its parent's flow; a slot template is its owner's container.
            if ($container->tag === 'template' && $slot === null && $container !== $template) {
                continue;
            }
            $owner = $slot !== null ? ($container->parent?->tag ?? '') : $container->tag;
            if ($slot === 'default' && $container->tag === 'template') {
                continue; // read through the owner's flow
            }
            if (self::grouped($container, $actionGroups)) {
                continue;
            }
            $run = [];
            foreach ([...$container->flow(), ''] as $child) {
                if ($child instanceof VueTemplate && $child->alternative()) {
                    continue;
                }
                if ($child instanceof VueTemplate && self::isAction($child)) {
                    $run[] = $child;

                    continue;
                }
                // Every control w-full is a hand-rolled stack, as an auth card's actions were before the kit's ActionGroup column.
                if (count($run) >= 2 && array_filter($run, fn (VueTemplate $control): bool => ! in_array('w-full', $control->classes(), true)) === []) {
                    $found[] = $this->finding($path, $run[0], "stacked full-width action controls in <{$owner}> are not grouped: put them in <ActionGroup direction=\"column\">, primary first, and drop their w-full");
                } elseif (count($run) >= 2) {
                    $found[] = $this->finding($path, $run[0], "adjacent action controls in <{$owner}> are not grouped: put them in a ButtonGroup, or render descriptors through ActionBar");
                }
                $run = [];
            }
        }

        return $found;
    }

    // ui.row-actions

    private const ROW_CONTROLS = ['Button', 'button', 'DropdownMenu', 'Dropdown', 'ButtonDropdown', 'DropdownConfirm'];

    private function rowActions(string $path, VueTemplate $template): array
    {
        $found = [];
        $controls = fn (VueTemplate $node): bool => array_filter($node->descendants(), fn (VueTemplate $child): bool => in_array($child->tag, self::ROW_CONTROLS, true)) !== [];
        foreach ($template->descendants() as $node) {
            if ($node->tag === 'table') {
                foreach ($node->descendants() as $cell) {
                    if ($cell->tag === 'td' && $controls($cell)) {
                        $found[] = $this->finding($path, $node, 'row actions hand-placed in a hand-rolled <table>: render it as a DataTable, with the row actions as descriptors on the row');
                        break;
                    }
                }
            }
            $slot = $node->slot();
            if ($slot !== null && $node->parent?->tag === 'DataTable' && preg_match('~^(?:cell-|row-actions$|\[`cell-)~', $slot) === 1 && $controls($node)) {
                $found[] = $this->finding($path, $node, "DataTable #{$slot} slot hand-places row actions: put them on the row as ActionDescriptor[] (actions) for the row ActionBar");
            }
        }

        return $found;
    }

    // ui.row-action-label

    /**
     * Row action labels are verbs ("Remove", "Revoke"): the record goes in the accessible name (`ariaLabel`) and the confirmation. Flags a label that
     * interpolates a value, in a PHP Action descriptor (`new Action("Revoke {$x->name}", …)`, `Action::make('Remove '.$grant)`, `new Action($model->name, …)`)
     * or a Vue action object (`label` beside `url` and `method`, as a template literal with `${…}`, a `+` concatenation or a bare expression), and a literal
     * label over 24 characters or 3 words, which the kit's RowActions clamps. A tenant term (`"New {$term}"`, `{term}`) is one word, not a value.
     *
     * Only row actions are read. A PHP Action is one when it ends up in a row: under the `actions` key of an array with an `id` (a DataTable row), under a
     * `rowActions` key, or among the arguments of an item or row object (`new WorkbenchItem(…)`, whose actions are its row menu), directly or through the
     * local variable, method or closure that holds it. A form submit, a page or header button and a link are not. A Vue action object is read in a file whose
     * template renders rows (RowActions, DataTable, ScrollList, Workbench). A label held in a local variable is read from its assignments, through a
     * closure's `use` and an arrow fn into the scope around them. Where a script's mask may be wrong (jsMask), its action objects are also read
     * unmasked, and one the masked read missed is an advisory finding: a scan that loses its place warns rather than hides it.
     *
     * @return list<array{target: string, check: string, line: int, advisory?: bool}>
     */
    private function rowActionLabels(): array
    {
        $found = [];
        foreach ((new \Tey\Standards\Source($this->config))->files($this->config->sourceRoots) as $path => $source) {
            if (preg_match('~\bAction\b~', $source) !== 1) {
                continue;
            }
            // Read on the masked source, so an Action in a string or a comment is not one and a comment inside the call doesn't hide it.
            $tree = self::phpTree($source);
            preg_match_all('~(?:\bnew\s+\\\\?(?:[\w\\\\]+\\\\)?Action|\bAction::(?:make|disabled))\s*\(~', $tree['masked'], $calls, PREG_OFFSET_CAPTURE);
            foreach ($calls[0] as [$call, $at]) {
                // Split and named on the masked source, so a comment or heredoc among the arguments can't move their bounds or hide a name; each
                // argument's text is the source's.
                $arguments = array_map(fn (array $argument): array => [substr($source, $argument[1], strlen($argument[0])), $argument[1], $argument[0]], self::arguments($tree['masked'], $at + strlen($call)));
                // The label is the `label:` argument wherever it sits, else the first positional one.
                $named = array_values(array_filter($arguments, fn (array $argument): bool => preg_match('~^label\s*:~', $argument[2]) === 1));
                [$arg, $offset, $code] = $named[0] ?? (isset($arguments[0]) && preg_match('~^\w+\s*:(?!:)~', $arguments[0][2]) !== 1 ? $arguments[0] : ['', $at, '']);
                $arg = trim(substr($arg, preg_match('~^label\s*:\s*~', $code, $name) === 1 ? strlen($name[0]) : 0));
                if ($arg === '') {
                    continue;
                }
                if (self::formSubmit($tree, $at)) {
                    continue; // a form's submit button, not a row action
                }
                $seen = [];
                if (! self::inRow($tree, $at, $seen)) {
                    continue; // a page or header button, a link or a form's submit: not a row action
                }
                // A label in a local variable is read from what is assigned to it; one with no assignment in reach (a parameter) stays a value.
                $held = preg_match('~^\$(\w+)$~', $arg, $variable) === 1 ? self::assigned($tree, $variable[1], $at) : [];
                foreach ($held === [] ? [$arg] : $held as $value) {
                    // A ternary whose arms are all literals picks a label: each arm is read as its own.
                    foreach (self::arms($value) ?? [$value] as $label) {
                        $literal = self::phpLiteral($label);
                        if (! $literal && ! self::interpolates($label)) {
                            continue; // a translation call or a constant: nothing to read
                        }
                        $found[] = $this->labelFinding($path, $source, $offset, $label, $literal ? stripcslashes(substr($label, 1, -1)) : self::withTerms($label));
                    }
                }
            }
        }
        foreach ($this->files() as $path => $source) {
            // The tags the template renders, so a mention in a comment or a script string is not one.
            $rendered = array_filter(VueTemplate::parse($source)?->descendants() ?? [], fn (VueTemplate $node): bool => preg_match('~^(?:RowActions|DataTable|ScrollList(?:Row)?|Workbench(?:Navigator)?)\b~', $node->tag) === 1);
            if ($rendered === []) {
                continue; // renders no rows: its action objects are page or header actions
            }
            // An HTML comment is matched whole first, so a `<script>` it mentions doesn't start a block; a `<!--` inside a block is the block's.
            preg_match_all('~<!--.*?(?:-->|$)|<script\b[^>]*>(.*?)</script\s*>~si', $source, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
            foreach (array_filter(array_column($blocks, 1), fn (array $block): bool => $block[0] !== null) as [$script, $offset]) {
                // Comments and literal contents masked, so a label in either is not read and their braces don't count when finding the object literal a label sits in.
                $read = $this->vueLabels($path, $source, $offset, $script, self::jsMask($script, $uncertain));
                array_push($found, ...$read);
                if ($uncertain === null) {
                    continue;
                }
                // Fail open: from where the mask may be wrong the script is also read unmasked, and an action the masked read missed is warned on, not
                // skipped, so a mask that lost its place can't hide a finding. A label in a comment or string past that point warns too.
                $line = substr_count(substr($source, 0, $offset + $uncertain), "\n") + 1;
                foreach ($this->vueLabels($path, $source, $offset, $script, $script, $uncertain) as $finding) {
                    if (! in_array($finding, $read, true)) {
                        $found[] = [...$finding, 'check' => $finding['check']." (read unmasked: the script can't be masked with certainty from line {$line}, so this only warns)", 'advisory' => true];
                    }
                }
            }
        }

        return array_values(array_filter($found));
    }

    /**
     * The findings for a script block's Vue action objects at or after $from, found on $masked (the script itself when it is read unmasked). A
     * label's text is the script's.
     *
     * @return list<array{target: string, check: string, line: int}>
     */
    private function vueLabels(string $path, string $source, int $offset, string $script, string $masked, int $from = 0): array
    {
        $found = [];
        // Found on the masked script, so a label in a comment or a string is not one and a comment after `label:` is not its value; the
        // label's text is the script's.
        preg_match_all('~\blabel\s*:\s*(?<arg>`(?:[^`\\\\]|\\\\.)*`|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^,}\n]+)(?<tail>\s*\+)?~', $masked, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $at = $match[0][1];
            if ($at < $from) {
                continue;
            }
            // Only an action object (url and method beside the label) counts: a form field's label is not an action.
            $window = self::enclosingObject($masked, $at);
            if (preg_match('~\burl\s*:~', $window) !== 1 || preg_match('~\bmethod\s*:~', $window) !== 1) {
                continue;
            }
            // A TypeScript type literal (`{ label: string; url: string }`) describes an action; it has no label to read.
            if (preg_match('~\blabel\s*\??\s*:\s*[^,;}]*;~', $window) === 1 || preg_match('~\blabel\s*\??\s*:\s*(?:string|number|boolean|any|unknown|null|undefined)(?:\s*\|\s*(?:string|number|boolean|any|unknown|null|undefined))*\s*[,}]~', $window) === 1) {
                continue;
            }
            // A form's submit object (`submit: { label, url, method }`) is not a row action.
            if (preg_match('~\b(?:submit|save)\s*:\s*$~', substr($masked, 0, max(0, strrpos(substr($masked, 0, $at), '{') ?: 0))) === 1) {
                continue;
            }
            $arg = substr($script, $match['arg'][1], strlen(rtrim($match['arg'][0])));
            if (($arms = self::arms(self::enclosingValue($script, $masked, $at))) !== null && count($arms) > 1) {
                foreach ($arms as $arm) {
                    $found[] = $this->labelFinding($path, $source, $offset + $at, $arm, stripcslashes(substr($arm, 1, -1)));
                }

                continue;
            }
            $quoted = in_array($arg[0] ?? '', ['"', "'", '`'], true);
            $concatenated = ($match['tail'][0] ?? '') !== '';
            $interpolated = ! $quoted || $concatenated || ($arg[0] === '`' && str_contains($arg, '${'));
            $found[] = $this->labelFinding($path, $source, $offset + $at, $arg, ! $interpolated ? stripcslashes(substr($arg, 1, -1)) : ($concatenated ? null : self::withTerms($arg)));
        }

        return array_values(array_filter($found));
    }

    /** Whether a PHP argument is one plain string literal. */
    private static function phpLiteral(string $arg): bool
    {
        return preg_match('~^\'(?:[^\'\\\\]|\\\\.)*\'$~s', $arg) === 1 || (preg_match('~^"(?:[^"\\\\]|\\\\.)*"$~s', $arg) === 1 && ! self::interpolates($arg));
    }

    /**
     * The arms of a ternary whose every arm is a plain string literal (`$on ? 'Switch off' : 'Switch on'`, nested and parenthesised arms too), or null.
     *
     * @return list<string>|null
     */
    private static function arms(string $expression): ?array
    {
        $expression = trim($expression);
        while (str_starts_with($expression, '(') && self::closing($expression, 0) === strlen($expression) - 1) {
            $expression = trim(substr($expression, 1, -1));
        }
        if (self::phpLiteral($expression) || preg_match('~^`[^`$]*`$~', $expression) === 1) {
            return [$expression];
        }
        // The first top-level `?` (not `?->`, `??` or `?.`) and its matching `:`.
        $depth = 0;
        $question = null;
        $nested = 0;
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];
            if ($char === '"' || $char === "'" || $char === '`') {
                for ($i++; $i < $length && $expression[$i] !== $char; $i++) {
                    $i += $expression[$i] === '\\' ? 1 : 0;
                }
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $char === '?' && ! in_array($expression[$i + 1] ?? '', ['-', '?', '.', ':'], true) && ($expression[$i - 1] ?? '') !== '?') {
                if ($question === null) {
                    $question = $i;
                } else {
                    $nested++;
                }
            } elseif ($depth === 0 && $char === ':' && $question !== null && ($expression[$i + 1] ?? '') !== ':' && ($expression[$i - 1] ?? '') !== ':') {
                if ($nested > 0) {
                    $nested--;

                    continue;
                }
                $then = self::arms(substr($expression, $question + 1, $i - $question - 1));
                $else = self::arms(substr($expression, $i + 1));

                return $then === null || $else === null ? null : [...$then, ...$else];
            }
        }

        return null;
    }

    /** The offset of the bracket closing the one at $open, or -1. */
    private static function closing(string $text, int $open): int
    {
        for ($i = $open, $depth = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];
            if ($char === '"' || $char === "'" || $char === '`') {
                for ($i++; $i < $length && $text[$i] !== $char; $i++) {
                    $i += $text[$i] === '\\' ? 1 : 0;
                }
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return -1;
    }

    /** The value of the `label:` property at $at, up to the comma or brace that ends it at its own depth. */
    private static function enclosingValue(string $script, string $masked, int $at): string
    {
        $colon = strpos($masked, ':', $at);
        if ($colon === false) {
            return '';
        }
        // Bounded on the masked script, so a comma or brace in a comment, string or template literal doesn't end it; the text is the script's.
        $arguments = self::arguments(str_replace('}', ')', substr($masked, $colon + 1)), 0);

        return isset($arguments[0]) ? substr($script, $colon + 1 + $arguments[0][1], strlen($arguments[0][0])) : '';
    }

    /**
     * Whether the Action at $at is a form's submit: returned by a `submit()`/`save()` method, or the value of a `submit`/`save` key or argument.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     */
    private static function formSubmit(array $tree, int $at): bool
    {
        // Read on the keyed source, so a comment (`// submit:`) or a string (`'save: draft' ?`) before a row action doesn't make it a submit.
        if (preg_match('~[\'"]?\b(?:submit|save)\b[\'"]?\s*(?:=>|:)\s*(?:[^,\[\](){};]*\?\s*)?$~', substr($tree['keyed'], 0, $at)) === 1) {
            return true;
        }
        // Inside the body of a submit() or save() method, by the bracket pairs, so a brace in a comment, string or heredoc doesn't move its end.
        foreach (self::openers($tree, $at) as $brace) {
            if ($brace >= 0 && $tree['masked'][$brace] === '{' && preg_match('~\bfunction\s*&?\s*(?:submit|save)\s*\([^;{}]*$~i', substr($tree['masked'], 0, $brace)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP source read for its structure: `masked` is the source with string contents (backtick shell strings, heredoc and nowdoc bodies too) and
     * comments blanked to spaces (same offsets), `keyed` is `masked` with one-word quoted strings kept, so a quoted key (`'actions' =>`) reads as
     * itself while a comment or any other string stays blank, `pairs` maps each bracket's open offset to its close and `closes` the other way, `opens`
     * lists the open offsets in order and `parents` maps each open offset to the bracket it sits in (-1 for the file).
     *
     * @return array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}
     */
    private static function phpTree(string $source): array
    {
        $masked = $source;
        $words = [];
        $pairs = [];
        $parents = [];
        $stack = [];
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($char === '"' || $char === "'" || $char === '`') {
                for ($from = $i + 1, $i++; $i < $length && $source[$i] !== $char; $i++) {
                    if ($source[$i] === '\\') {
                        $masked[$i++] = ' ';
                    }
                    if ($i < $length) {
                        $masked[$i] = ' ';
                    }
                }
                if ($char !== '`' && preg_match('~^\w+$~', substr($source, $from, $i - $from)) === 1) {
                    $words[$from] = $i - $from;
                }
            } elseif ($char === '<' && preg_match('~\G<<<\h*(["\']?)([A-Za-z_\x80-\xff][\w\x80-\xff]*)\1\r?\n~', $source, $open, 0, $i) === 1) {
                // A heredoc or nowdoc: its body runs to the line that starts with its identifier.
                $body = $i + strlen($open[0]);
                $end = preg_match('~^\h*'.$open[2].'(?![\w\x80-\xff])~m', $source, $close, PREG_OFFSET_CAPTURE, $body) === 1 ? $close[0][1] : $length;
                for ($i = $body; $i < $end; $i++) {
                    $masked[$i] = ' ';
                }
                $i--;
            } elseif (($char === '/' && $next === '/') || ($char === '#' && $next !== '[')) {
                for (; $i < $length && $source[$i] !== "\n"; $i++) {
                    $masked[$i] = ' ';
                }
            } elseif ($char === '/' && $next === '*') {
                for ($end = ($close = strpos($source, '*/', $i + 2)) === false ? $length : $close + 2; $i < $end; $i++) {
                    $masked[$i] = ' ';
                }
                $i--;
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $parents[$i] = $stack === [] ? -1 : $stack[array_key_last($stack)];
                $stack[] = $i;
            } elseif (in_array($char, [')', ']', '}'], true) && $stack !== []) {
                $pairs[array_pop($stack)] = $i;
            }
        }
        $keyed = $masked;
        foreach ($words as $from => $size) {
            for ($j = $from; $j < $from + $size; $j++) {
                $keyed[$j] = $source[$j];
            }
        }
        $opens = array_keys($pairs);
        sort($opens);

        return ['source' => $source, 'masked' => $masked, 'keyed' => $keyed, 'pairs' => $pairs, 'closes' => array_flip($pairs), 'opens' => $opens, 'parents' => $parents];
    }

    /**
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @return list<int> the open offsets of the brackets around $at, innermost first, then -1 for the file
     */
    private static function openers(array $tree, int $at): array
    {
        // The last bracket opened before $at, by binary search; the brackets around $at are it or its ancestors, as brackets nest.
        [$low, $high] = [0, count($tree['opens']) - 1];
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            [$low, $high] = $tree['opens'][$middle] < $at ? [$middle + 1, $high] : [$low, $middle - 1];
        }
        $around = [];
        for ($open = $tree['opens'][$high] ?? -1; $open >= 0; $open = $tree['parents'][$open]) {
            if ($at < ($tree['pairs'][$open] ?? -1)) {
                $around[] = $open;
            }
        }

        return [...$around, -1];
    }

    /**
     * Where the element holding $at starts in the bracket at $open: an argument or array item in `(` and `[`, an arm in a `match` body, a statement
     * in any other `{` and the file (-1).
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     */
    private static function elementStart(array $tree, int $open, int $at): int
    {
        $statement = $open < 0 || ($tree['masked'][$open] === '{' && ! self::matchBody($tree, $open));
        for ($i = $at - 1; $i > $open; $i--) {
            $char = $tree['masked'][$i];
            if (isset($tree['closes'][$i])) {
                if ($statement && $char === '}') {
                    return $i + 1;
                }
                $i = $tree['closes'][$i];
            } elseif ($char === ($statement ? ';' : ',')) {
                return $i + 1;
            }
        }

        return $open + 1;
    }

    /**
     * Whether the brace at $open opens a `match` body, whose arms are expressions, not statements.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     */
    private static function matchBody(array $tree, int $open): bool
    {
        $close = $open - 1;
        while ($close >= 0 && ctype_space($tree['masked'][$close])) {
            $close--;
        }
        $paren = $tree['closes'][$close] ?? -1;

        return $paren > 0 && $tree['masked'][$close] === ')' && preg_match('~(?<![\w$>:])match\s*$~i', substr($tree['masked'], max(0, $paren - 20), min($paren, 20))) === 1;
    }

    /**
     * Whether the Action at $at ends up in a row, read outward through the brackets around it: under the `actions` key of an array with an `id` (a
     * DataTable row), under a `rowActions` key, or among the arguments of an item or row object (`new WorkbenchItem(…)`). Assigned to a variable, it follows
     * the variable's uses in its scope (scope()); returned, the calls that reach the function or method (callers()) or where the closure goes. A
     * `match` arm is read through to the match.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @param  array<int, true>  $seen  offsets already followed
     */
    private static function inRow(array $tree, int $at, array &$seen): bool
    {
        if (isset($seen[$at])) {
            return false;
        }
        $seen[$at] = true;
        $inner = $at;
        foreach (self::openers($tree, $at) as $open) {
            $bracket = $open < 0 ? '{' : $tree['masked'][$open];
            $bracket = $bracket === '{' && $open >= 0 && self::matchBody($tree, $open) ? 'match' : $bracket;
            // The element from its first code: a comment before a key or a statement is blank in the masked source.
            $start = self::elementStart($tree, $open, $inner);
            $start += strspn($tree['masked'], " \t\r\n", $start);
            if (substr($tree['masked'], $start, 5) === '<?php') {
                $start += 5 + strspn($tree['masked'], " \t\r\n", $start + 5);
            }
            $element = substr($tree['keyed'], $start, max(0, $inner - $start));
            $inner = $open;
            if ($bracket === '(' && preg_match('~\bnew\s+\\\\?(?:[\w\\\\]+\\\\)?\w*(?:Item|Row)(?:Data)?\s*$~', substr($tree['masked'], max(0, $open - 200), min($open, 200))) === 1) {
                return true; // an item or row object: a WorkbenchItem's actions are its row menu
            }
            if ($bracket !== '{') {
                if (preg_match('~^([\'"]?)(actions|rowActions)\1\s*(?:=>|:(?!:))~', $element, $key) === 1) {
                    return $key[2] === 'rowActions' || ($bracket === '[' && self::rowArray($tree, $open));
                }

                continue; // an argument, an item or another key: read the bracket around it
            }
            if (preg_match('~^return\b~', $element) === 1) {
                [$name, $keyword] = self::owner($tree, $at) ?? ['', -1];
                if ($name === '') {
                    return $keyword >= 0 && self::inRow($tree, $keyword, $seen); // a closure: where it goes
                }
                foreach (self::callers($tree, $name, $keyword) as $call) {
                    if (self::inRow($tree, $call, $seen)) {
                        return true;
                    }
                }

                return false;
            }
            if (preg_match('~^\$(\w+)\s*(?:\[[^\]]*\]\s*)*(?:\?\?)?=(?![=>])~', $element, $variable) === 1) {
                $scope = self::scope($tree, $variable[1], $start);
                // Its uses in the same scope, not the assignments to it, nor a variable of the same name in a closure of its own.
                [$from, $to] = self::span($tree, $scope);
                preg_match_all('~\$'.preg_quote($variable[1], '~').'\b(?!\s*(?:\[[^\]]*\]\s*)*(?:\?\?)?=(?![=>]))~', substr($tree['masked'], $from, $to - $from), $uses, PREG_OFFSET_CAPTURE);
                foreach ($uses[0] as [, $use]) {
                    if (self::scope($tree, $variable[1], $from + $use) === $scope && self::inRow($tree, $from + $use, $seen)) {
                        return true;
                    }
                }
            }

            return false;
        }

        return false;
    }

    /**
     * Whether the array at $open is a DataTable row: it has its own `id` key.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     */
    private static function rowArray(array $tree, int $open): bool
    {
        preg_match_all('~([\'"])id\1\s*=>~', substr($tree['keyed'], $open, $tree['pairs'][$open] - $open), $ids, PREG_OFFSET_CAPTURE);
        foreach ($ids[0] as [, $id]) {
            if (self::openers($tree, $open + $id)[0] === $open) {
                return true;
            }
        }

        return false;
    }

    /**
     * The function whose body holds $at: its name ('' for a closure), its `function` keyword's offset and its body's opening brace; null outside any.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @return array{0: string, 1: int, 2: int}|null
     */
    private static function owner(array $tree, int $at): ?array
    {
        foreach (self::openers($tree, $at) as $brace) {
            if ($brace >= 0 && $tree['masked'][$brace] === '{' && preg_match('~\bfunction\s*&?\s*(\w*)\s*\([^;{}]*$~', substr($tree['masked'], 0, $brace), $head, PREG_OFFSET_CAPTURE) === 1) {
                return [$head[1][0], $head[0][1], $brace];
            }
        }

        return null;
    }

    /**
     * The scope `$name` at $at belongs to: the body brace of the function that holds it, or of the scope around a closure that captures it with `use`;
     * the parameter list of an arrow fn whose own parameter it is; -1 for the file. An arrow fn otherwise shares the scope around it.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     */
    private static function scope(array $tree, string $name, int $at): int
    {
        [, $keyword, $body] = self::owner($tree, $at) ?? ['', -1, -1];
        $variable = '\$'.preg_quote($name, '~').'\b';
        $from = max(0, $body);
        $shadow = null;
        preg_match_all('~(?<![\w$])(?<!->)(?<!::)fn\s*&?\s*\(~', substr($tree['masked'], $from, $at - $from), $arrows, PREG_OFFSET_CAPTURE);
        foreach ($arrows[0] as [$arrow, $offset]) {
            $paren = $from + $offset + strlen($arrow) - 1;
            $around = self::openers($tree, $at);
            $depth = array_search(self::openers($tree, $from + $offset)[0], $around, true);
            // The arrow fn holds $at when $at sits in the element its `fn` starts, and its parameters name $name; the innermost one wins.
            if ($depth !== false && self::elementStart($tree, $around[$depth], $depth === 0 ? $at : $around[$depth - 1]) <= $from + $offset
                && preg_match('~'.$variable.'~', substr($tree['masked'], $paren, ($tree['pairs'][$paren] ?? $paren) - $paren)) === 1) {
                $shadow = $paren;
            }
        }
        if ($shadow !== null) {
            return $shadow;
        }
        $head = $keyword < 0 ? '' : substr($tree['masked'], $keyword, $body - $keyword);
        if (preg_match('~^function\s*&?\s*\(~', $head) === 1 && preg_match('~\)\s*use\s*\([^)]*&?'.$variable.'~', $head) === 1) {
            return self::scope($tree, $name, $keyword); // captured from the scope around the closure
        }

        return $body;
    }

    /**
     * The offsets a scope from scope() spans: a function body's braces, an arrow fn from its parameters to the end of the bracket around it, or the file.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @return array{0: int, 1: int}
     */
    private static function span(array $tree, int $scope): array
    {
        if ($scope < 0) {
            return [0, strlen($tree['masked'])];
        }
        $around = self::openers($tree, $scope)[0];

        return [$scope, $tree['masked'][$scope] === '{' ? $tree['pairs'][$scope] : ($around < 0 ? strlen($tree['masked']) : $tree['pairs'][$around])];
    }

    /**
     * The values assigned to `$name` before $at in its scope (scope()): in the function that holds it (the file outside one), and through a closure's
     * `use` or an arrow fn in the scope around them, not in a closure of its own. A parameter with no assignment, an arrow fn's included, has none. `??=`
     * adds a value and `.=` concatenates its operand onto each value so far.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @return list<string>
     */
    private static function assigned(array $tree, string $name, int $at): array
    {
        $scope = self::scope($tree, $name, $at);
        $from = self::span($tree, $scope)[0];
        preg_match_all('~\$'.preg_quote($name, '~').'\b\s*(\.|\?\?)?=(?![=>])~', substr($tree['masked'], $from, max(0, $at - $from)), $assignments, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $values = [];
        foreach ($assignments as $assignment) {
            [$text, $offset] = $assignment[0];
            $offset += $from;
            if (self::scope($tree, $name, $offset) !== $scope) {
                continue; // another variable of the same name: a closure's own, or an arrow fn's parameter
            }
            $start = $offset + strlen($text);
            for ($end = $start, $length = strlen($tree['masked']); $end < $length && $tree['masked'][$end] !== ';'; $end++) {
                $end = $tree['pairs'][$end] ?? $end;
            }
            // The value's text from its first to its last code, so a comment around it is not part of it.
            $code = substr($tree['masked'], $start, $end - $start);
            $value = substr($tree['source'], $start + strlen($code) - strlen(ltrim($code)), strlen(trim($code)));
            $values = ($assignment[1][0] ?? '') === '.'
                ? array_map(fn (string $before): string => $before.'.'.$value, $values === [] ? ['$'.$name] : $values)
                : [...$values, $value];
        }

        return $values;
    }

    /**
     * The offsets of the calls that reach the function or method declared at $keyword. A function's are its plain calls. A method's are its calls on
     * `$this`, `self`, `static` or `parent` from a class that has it (its own, or one that inherits it in this file without declaring its own), on a
     * class name that has it, and on any other receiver only when no other class in the file declares a method of that name.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @return list<int>
     */
    private static function callers(array $tree, string $name, int $keyword): array
    {
        $quoted = preg_quote($name, '~');
        $classes = self::classes($tree);
        $declaring = self::classAt($tree, $classes, $keyword);
        if ($declaring === null) {
            preg_match_all('~(?<![\w$\\\\>:])\\\\?(?:\w+\\\\)*'.$quoted.'\s*\(~i', $tree['masked'], $calls, PREG_OFFSET_CAPTURE);

            return array_values(array_filter(array_column($calls[0], 1), fn (int $call): bool => preg_match('~(?<![\w$])(?:function|new)\s*&?\s*$~i', substr($tree['masked'], max(0, $call - 20), min($call, 20))) !== 1));
        }
        $others = array_filter($classes, fn (array $class, int $brace): bool => $brace !== $declaring && isset($class['methods'][strtolower($name)]), ARRAY_FILTER_USE_BOTH);
        preg_match_all('~(?:->|::)\s*'.$quoted.'\s*\(~i', $tree['masked'], $calls, PREG_OFFSET_CAPTURE);
        $reached = [];
        foreach ($calls[0] as [, $call]) {
            preg_match('~(?:(?<this>\$this\s*\??->|\b(?:self|static)\s*::)|\bparent\s*::|(?<![\w$\\\\])\\\\?(?:\w+\\\\)*(?<class>\w+)\s*::|(?<other>))\s*$~i', substr($tree['masked'], max(0, $call - 200), min($call, 200)).substr($tree['masked'], $call, 2), $receiver);
            $caller = self::classAt($tree, $classes, $call);
            $from = match (true) {
                ($receiver['this'] ?? '') !== '' => [$caller],
                ($receiver['class'] ?? '') !== '' => array_keys(array_filter($classes, fn (array $class): bool => strcasecmp($class['name'], $receiver['class']) === 0)),
                isset($receiver['other']) => $others === [] ? [$declaring] : [],
                default => $caller === null ? [] : self::parents($classes, $caller), // parent::
            };
            if (array_filter($from, fn (?int $class): bool => $class !== null && self::inherits($classes, $class, $declaring, strtolower($name))) !== []) {
                $reached[] = $call;
            }
        }

        return $reached;
    }

    /**
     * The file's classes, traits, enums and interfaces (anonymous classes too), by body brace: the name, the short names it extends or uses, and the
     * methods it declares, lowercased.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @return array<int, array{name: string, parents: list<string>, methods: array<string, true>}>
     */
    private static function classes(array $tree): array
    {
        preg_match_all('~(?<![\w$>:])(?:(?:class|trait|enum|interface)\s+(\w+)|new\s+class\b)([^;{}]*)\{~i', $tree['masked'], $heads, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $classes = [];
        foreach ($heads as $head) {
            preg_match('~\bextends\s+([\w\\\\\s,]+?)(?:\bimplements\b|$)~i', $head[2][0], $extends);
            $classes[$head[0][1] + strlen($head[0][0]) - 1] = ['name' => $head[1][0] ?? '', 'parents' => self::shortNames($extends[1] ?? ''), 'methods' => []];
        }
        preg_match_all('~(?<![\w$>:])(?:use\s+([\w\\\\\s,]+?)\s*[;{]|function\s*&?\s*(\w+)\s*\()~i', $tree['masked'], $members, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($members as $member) {
            $brace = self::openers($tree, $member[0][1])[0];
            if (! isset($classes[$brace])) {
                continue; // a namespace import, a function outside a class or one nested in a body
            }
            if (($member[2][0] ?? '') !== '') {
                $classes[$brace]['methods'][strtolower($member[2][0])] = true;
            } else {
                array_push($classes[$brace]['parents'], ...self::shortNames($member[1][0]));
            }
        }

        return $classes;
    }

    /** @return list<string> the lowercased short names in a comma-separated list of class names */
    private static function shortNames(string $list): array
    {
        return array_values(array_filter(array_map(fn (string $name): string => strtolower((string) preg_replace('~^.*\\\\~', '', trim($name))), explode(',', $list))));
    }

    /**
     * The body brace of the innermost class around $at, or null.
     *
     * @param  array{source: string, masked: string, keyed: string, pairs: array<int, int>, closes: array<int, int>, opens: list<int>, parents: array<int, int>}  $tree
     * @param  array<int, array{name: string, parents: list<string>, methods: array<string, true>}>  $classes
     */
    private static function classAt(array $tree, array $classes, int $at): ?int
    {
        foreach (self::openers($tree, $at) as $open) {
            if (isset($classes[$open])) {
                return $open;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{name: string, parents: list<string>, methods: array<string, true>}>  $classes
     * @return list<int> the classes in the file that the class at $brace extends or uses
     */
    private static function parents(array $classes, int $brace): array
    {
        return array_keys(array_filter($classes, fn (array $class): bool => $class['name'] !== '' && in_array(strtolower($class['name']), $classes[$brace]['parents'], true)));
    }

    /**
     * Whether a call of `$method` on the class at $from reaches the one $declaring declares: it is that class, or inherits from it in this file
     * without declaring its own.
     *
     * @param  array<int, array{name: string, parents: list<string>, methods: array<string, true>}>  $classes
     */
    private static function inherits(array $classes, int $from, int $declaring, string $method, int $depth = 0): bool
    {
        if ($from === $declaring) {
            return true;
        }
        if (isset($classes[$from]['methods'][$method]) || $depth > 10) {
            return false;
        }
        foreach (self::parents($classes, $from) as $parent) {
            if (self::inherits($classes, $parent, $declaring, $method, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An interpolated label whose only values are tenant terms (`"New {$term}"`, `'All '.$terms`, `` `New ${term}` ``) as text, each term one `{term}`
     * word; null when it interpolates anything else.
     */
    private static function withTerms(string $label): ?string
    {
        $text = '';
        foreach (self::concatenation($label) as $part) {
            if (self::isTerm($part)) {
                $text .= '{term}';
            } elseif (self::phpLiteral($part)) {
                $text .= stripcslashes(substr($part, 1, -1));
            } elseif (preg_match('~^(["`])(.*)\1$~s', $part, $quoted) === 1) {
                $other = false;
                // `(?|…)` numbers each form's expression 1: `${term}`, `{$term}` or `$term`.
                $inner = preg_replace_callback('~(?|\$\{([^}]*)\}|\{(\$[^}]*)\}|(\$[A-Za-z_]\w*(?:->\w+)*))~', function (array $value) use (&$other): string {
                    $other = $other || ! self::isTerm(trim($value[1]));

                    return '{term}';
                }, $quoted[2]);
                if ($other) {
                    return null;
                }
                $text .= stripcslashes((string) $inner);
            } else {
                return null;
            }
        }

        return $text;
    }

    /** Whether an expression is a tenant term: a variable or property path with a `term` or `terms` segment (`$term`, `$this->terms->plural`, `clientTerm`). */
    private static function isTerm(string $expression): bool
    {
        if (preg_match('~^\$?[A-Za-z_]\w*(?:(?:\??->|\.)[A-Za-z_]\w*)*$~', $expression) !== 1) {
            return false;
        }
        $segments = preg_split('~\??->|\.|_|(?<=[a-z0-9])(?=[A-Z])~', ltrim($expression, '$')) ?: [];

        return array_intersect(array_map(strtolower(...), $segments), ['term', 'terms']) !== [];
    }

    /** @return list<string> the operands of a PHP `.` concatenation (`'All '.$terms`), or the label alone */
    private static function concatenation(string $label): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        for ($i = 0, $length = strlen($label); $i < $length; $i++) {
            $char = $label[$i];
            if ($char === '"' || $char === "'" || $char === '`') {
                for ($i++; $i < $length && $label[$i] !== $char; $i++) {
                    $i += $label[$i] === '\\' ? 1 : 0;
                }
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($char === '.' && $depth === 0 && (preg_match('~^\s*[\'"$]~', substr($label, $i + 1)) === 1 || preg_match('~[\'"]\s*$~', substr($label, 0, $i)) === 1)) {
                $parts[] = trim(substr($label, $start, $i - $start));
                $start = $i + 1;
            }
        }

        return [...$parts, trim(substr($label, $start))];
    }

    /** A variable in PHP source outside single quotes: `$name`, `{$…}` or `${…}`; a literal `$5` is not one. */
    private static function interpolates(string $arg): bool
    {
        $unquoted = (string) preg_replace('~\'(?:[^\'\\\\]|\\\\.)*\'~s', "''", $arg);

        return preg_match('~(?<!\\\\)\$(?:[A-Za-z_\x80-\xff]|\{)~', $unquoted) === 1;
    }

    /**
     * The top-level arguments of a call, from just after its `(`: each argument's text and offset, quotes and nested brackets respected.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function arguments(string $source, int $from): array
    {
        $arguments = [];
        $depth = 0;
        $start = $from;
        $length = strlen($source);
        for ($i = $from; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '"' || $char === "'") {
                for ($i++; $i < $length && $source[$i] !== $char; $i++) {
                    $i += $source[$i] === '\\' ? 1 : 0;
                }
            } elseif (in_array($char, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']', '}'], true) && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && ($char === ',' || $char === ')')) {
                $text = substr($source, $start, $i - $start);
                if (trim($text) !== '') {
                    $arguments[] = [trim($text), $start + strlen($text) - strlen(ltrim($text))];
                }
                if ($char === ')') {
                    break;
                }
                $start = $i + 1;
            }
        }

        return $arguments;
    }

    /** The object literal around an offset, by brace depth, so a nested `confirm: { … }` does not end it early. */
    private static function enclosingObject(string $script, int $at): string
    {
        $open = null;
        for ($i = $at - 1, $depth = 0; $i >= 0; $i--) {
            if ($script[$i] === '}') {
                $depth++;
            } elseif ($script[$i] === '{' && $depth-- === 0) {
                $open = $i;
                break;
            }
        }
        for ($i = $at, $depth = 0, $length = strlen($script); $open !== null && $i < $length; $i++) {
            if ($script[$i] === '{') {
                $depth++;
            } elseif ($script[$i] === '}' && $depth-- === 0) {
                return substr($script, $open, $i - $open + 1);
            }
        }

        return '';
    }

    /**
     * A script with its comments and the contents of its string, template and regex literals blanked to spaces (same offsets, delimiters kept). A `/`
     * after an operator, an opening bracket or a separator starts a regex literal, so a quote in `/['"]/` doesn't open a string.
     *
     * This is a scan, not a JavaScript tokenizer, and it guesses in two places. Whether a `/` starts a regex or divides depends on the grammar after a
     * word (`return /re/` against `total / 2`), a `)` (`if (x) /re/` against `(a + b) / 2`), a `}`, a `+` or a `-` (`i++ / 2`). And a template
     * literal's `${…}` ends at the brace that balances its own, counted without reading the strings, templates or regexes the expression holds. A wrong
     * guess can read a literal's quote as one that opens a string and blanks the code after it. So $uncertain is set to the first offset the mask may
     * be wrong from: such a `/` where a regex could start, an interpolation that holds a quote, a backtick or a `/`, and, as signs that the scan lost
     * its place anyway, a quoted string read past a line break (JavaScript allows none) or a literal or block comment still open at the end of the
     * script. It is null when none occurs.
     */
    private static function jsMask(string $script, ?int &$uncertain = null): string
    {
        $uncertain = null;
        $masked = $script;
        $previous = '';
        for ($i = 0, $length = strlen($script); $i < $length; $i++) {
            $char = $script[$i];
            $next = $script[$i + 1] ?? '';
            if ($char === '/' && ($next === '/' || $next === '*')) {
                $close = $next === '/' ? strpos($script, "\n", $i) : strpos($script, '*/', $i + 2);
                if ($close === false && $next === '*') {
                    $uncertain ??= $i;
                }
                for ($end = $close === false ? $length : $close + ($next === '/' ? 0 : 2); $i < $end; $i++) {
                    $masked[$i] = ' ';
                }
                $i--;
            } elseif ($char === '"' || $char === "'" || $char === '`') {
                // To the closing quote; a template literal's `${…}` runs to its own closing brace.
                for ($open = $i, $i++, $depth = 0; $i < $length && ($depth > 0 || $script[$i] !== $char); $i++) {
                    if (($char !== '`' && $script[$i] === "\n") || ($depth > 0 && str_contains('\'"`/', $script[$i]))) {
                        $uncertain ??= $open; // a line break in a quoted string (an escaped one is skipped below), or a literal in an interpolation
                    }
                    if ($script[$i] === '\\' || ($char === '`' && $script[$i] === '$' && ($script[$i + 1] ?? '') === '{')) {
                        $depth += $script[$i] === '$' ? 1 : 0;
                        $masked[$i++] = ' ';
                    } elseif ($depth > 0 && ($script[$i] === '{' || $script[$i] === '}')) {
                        $depth += $script[$i] === '{' ? 1 : -1;
                    }
                    if ($i < $length) {
                        $masked[$i] = ' ';
                    }
                }
                if ($i >= $length) {
                    $uncertain ??= $open;
                }
                $previous = $char;
            } elseif ($char === '/' && preg_match('~\G/(?:[^/\\\\\[\n]|\\\\.|\[(?:[^\]\\\\\n]|\\\\.)*\])+/~', $script, $regex, 0, $i) === 1) {
                // A regex could start here. After a word, `)`, `}`, `+` or `-` only the grammar can tell, so the reading below is a guess.
                if (preg_match('~^[\w$\x80-\xff)}+-]$~', $previous) === 1) {
                    $uncertain ??= $i;
                }
                if ($previous === '' || str_contains('(,=:[!&|?{};+-*%<>~^', $previous)) {
                    for ($end = $i + strlen($regex[0]) - 1, $i++; $i < $end; $i++) {
                        $masked[$i] = ' ';
                    }
                }
                $previous = '/';
            } elseif (! ctype_space($char)) {
                $previous = $char;
            }
        }

        return $masked;
    }

    /**
     * A finding for the label $arg, read as $text, or null when $text is a short verb. A null $text interpolates a value.
     *
     * @return array{target: string, check: string, line: int}|null
     */
    private function labelFinding(string $path, string $source, int $offset, string $arg, ?string $text): ?array
    {
        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
        if ($text === null) {
            return ['target' => $path, 'check' => "row action label {$arg} interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm", 'line' => $line];
        }
        $words = preg_split('~\s+~', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (mb_strlen(trim($text)) > self::LABEL_MAX_CHARACTERS || count($words) > self::LABEL_MAX_WORDS) {
            return ['target' => $path, 'check' => "row action label {$arg} is over ".self::LABEL_MAX_CHARACTERS.' characters or '.self::LABEL_MAX_WORDS.' words: RowActions clamps it; keep the label a verb and put the record in the confirm', 'line' => $line];
        }

        return null;
    }

    // ui.page-title-dup

    private function pageTitles(string $path, VueTemplate $template): array
    {
        $found = [];
        foreach (self::pages($template) as $page) {
            $title = $page->attribute('title');
            $draws = $page->attribute('heading') !== 'false' && $page->bound('heading') !== 'false';
            foreach ($page->descendants() as $node) {
                if (! in_array($node->tag, ['SectionHeader', 'PageSection'], true)) {
                    continue;
                }
                $own = $node->attribute('title');
                if ($title !== null && $own !== null && strcasecmp(trim($own), trim($title)) === 0) {
                    $found[] = $this->finding($path, $node, "<{$node->tag}> repeats the Page title \"{$title}\": the heading is Page's; drop it or give the section its own title");
                } elseif ($node->tag === 'SectionHeader' && $node->attribute('size') === 'page' && $draws) {
                    $found[] = $this->finding($path, $node, '<SectionHeader size="page"> draws a second page heading inside a Page that draws its own: drop it, or pass the title to Page');
                }
            }
            foreach ($page->descendants() as $node) {
                if (! in_array($node->tag, ['Button', 'Link', 'a', 'button', 'InertiaAction'], true) || self::inPageActions($node, $page)
                    || $node->closest(['EmptyState']) !== null) {
                    continue;
                }
                if (preg_match('~[\'"`]([\w.-]+\.create)[\'"`]|([\w.]+\.create)\s*\(~', $node->attributes, $route) === 1) {
                    $name = $route[1] !== '' ? $route[1] : $route[2];
                    $found[] = $this->finding($path, $node, "create action ({$name}) sits outside the Page header: pass it in <Page :actions>");
                } elseif (preg_match('~^(New|Create|Add)\b~', $node->text()) === 1
                    && $node->closest(['form', 'Form', 'FormCard', 'Repeater', 'Dialog', 'Modal', 'Sheet', 'DataTable']) === null) {
                    // The label test is English-only: it stays advisory even once the rule is opted in.
                    $found[] = $this->finding($path, $node, 'a "'.$node->text().'" action sits outside the Page header: if it creates the page\'s record, pass it in <Page :actions>', true);
                }
            }
        }

        return $found;
    }

    /** In this Page's own #actions slot, not a SectionHeader's or any other component's. */
    private static function inPageActions(VueTemplate $node, VueTemplate $page): bool
    {
        for (; $node !== null && $node !== $page; $node = $node->parent) {
            if ($node->slot() === 'actions' && $node->parent === $page) {
                return true;
            }
        }

        return false;
    }

    // ui.no-outer-margins (and flush)

    /** @return list<string> */
    private static function margins(VueTemplate $node): array
    {
        return array_values(array_filter($node->classes(), fn (string $token): bool => preg_match(self::MARGIN, VueTemplate::utility($token)) === 1));
    }

    private function outerMargins(string $path, string $source, VueTemplate $template): array
    {
        $found = [];
        foreach (self::pages($template) as $page) {
            $inFlow = 0;
            foreach ($page->flow() as $child) {
                if (is_string($child)) {
                    $inFlow++;

                    continue;
                }
                if (($margins = self::margins($child)) !== []) {
                    $found[] = $this->finding($path, $child, "<{$child->tag}> directly in <Page> carries ".implode(' ', $margins).': the Page\'s gap owns the spacing; use Stack (gap-*) or PageSection');
                }
                if (! $child->native() && ($component = $this->component($path, $source, $child->tag)) !== null) {
                    $root = VueTemplate::parse($this->files()[$component]);
                    foreach ($root?->elements() ?? [] as $element) {
                        if (($margins = self::margins($element)) !== []) {
                            $found[] = $this->finding($component, $element, 'root element carries '.implode(' ', $margins)." and a Page renders it directly ({$path}): the Page's gap owns the spacing; drop the margin");
                        }
                    }
                }
                if (! $child->alternative() && ! in_array($child->tag, self::OUT_OF_FLOW, true) && ! $child->hasUtility('~^(absolute|fixed|hidden|sr-only)$~')) {
                    $inFlow++;
                }
            }
            if ($inFlow > 1 && $page->has('flush') && $page->bound('flush') !== 'false') {
                $found[] = $this->finding($path, $page, '<Page flush> holds more than one in-flow child: flush is for one full-bleed child; remove flush and let the Page gap its blocks');
            }
        }

        return $found;
    }

    /** The app `.vue` file a component tag is imported from, when the import is relative or `@/`. */
    private function component(string $path, string $source, string $tag): ?string
    {
        if (preg_match('~\bimport\s+'.preg_quote($tag, '~').'\s+from\s+([\'"])([^\'"]+\.vue)\1~', FrontendSource::script($path, $source), $match) !== 1) {
            return null;
        }
        $from = $match[2];
        $resolved = str_starts_with($from, '@/') ? 'resources/js/'.substr($from, 2) : (str_starts_with($from, '.') ? dirname($path).'/'.$from : null);
        if ($resolved === null) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $resolved) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }
        $resolved = implode('/', $parts);

        return isset($this->files()[$resolved]) ? $resolved : null;
    }

    // ui.page-grid

    private function pageGrids(string $path, VueTemplate $template): array
    {
        $found = [];
        foreach (self::pages($template) as $page) {
            foreach ($page->flow() as $child) {
                if (! $child instanceof VueTemplate) {
                    continue;
                }
                $cells = array_values(array_filter($child->flow(), fn (VueTemplate|string $cell): bool => $cell instanceof VueTemplate));
                // The dashboard's widget grid has its own span model.
                if (array_filter($cells, fn (VueTemplate $cell): bool => str_ends_with($cell->tag, 'Widget')) !== []) {
                    continue;
                }
                if ($child->hasUtility('~^grid$~') && $child->hasUtility('~^grid-cols-~')) {
                    $found[] = $this->finding($path, $child, 'page-level grid-cols on a Page child: use PageGrid with a preset (main-aside, halves, thirds)');

                    continue;
                }
                foreach ($cells as $cell) {
                    if ($cell->hasUtility('~^col-span-~')) {
                        $found[] = $this->finding($path, $cell, 'page-level col-span: use PageGridItem :span inside a PageGrid');
                    }
                }
            }
        }

        return $found;
    }

    // ui.primitive-pointers

    /** @var list<string>|null */
    private ?array $exports = null;

    /** Whether the kit the app runs (installed, else the shipped snapshot) exports the primitive. Until it does, its pointer stays advisory. */
    private function shipped(string $primitive): bool
    {
        if ($this->exports === null) {
            $barrel = $this->config->base.'/vendor/tey/kit/'.KitExports::BARREL;
            $this->exports = is_file($barrel) ? KitExports::parse((string) file_get_contents($barrel)) : KitExports::snapshot();
        }

        return $primitive === 'Stack' ? array_intersect(self::STACKS, $this->exports) !== [] : in_array($primitive, $this->exports, true);
    }

    private function pointer(string $path, VueTemplate|int $at, string $primitive, string $shape): array
    {
        $shipped = $this->shipped($primitive); // loads the export set before the name is chosen
        $name = $primitive === 'Stack' && $this->exports !== null && ! in_array('Stack', $this->exports, true) && in_array('BlockStack', $this->exports, true) ? 'BlockStack' : $primitive;
        $line = $at instanceof VueTemplate ? $at->line : $at;

        return ['target' => $path, 'check' => "hand-rolled {$primitive} ({$shape}): use the kit's {$name}", 'line' => $line, ...($shipped ? [] : ['advisory' => true])];
    }

    /**
     * A check that is advisory by kind (the English label test, a pointer to a primitive the kit has not shipped, or a row action label read from a
     * script the scan can't mask with certainty): its allowlist entry never fails as stale, even once its finding is gone, because such a finding
     * never failed in the first place.
     */
    public function quiet(string $check): bool
    {
        if (preg_match('~^a ".*" action sits outside the Page header: if it creates~s', $check) === 1) {
            return true;
        }
        // A row action label read past where a script's mask may be wrong only warns.
        if (preg_match('~^row action label .* \\(read unmasked: the script can\'t be masked with certainty from line \\d+, so this only warns\\)$~s', $check) === 1) {
            return true;
        }

        return preg_match('~^hand-rolled (\w+) \(~', $check, $match) === 1 && ! $this->shipped($match[1]);
    }

    private function primitives(string $path, ?VueTemplate $template): array
    {
        $found = [];
        $file = basename($path);
        if (preg_match('~(AsideContainer|^SidebarFilterContainer)\.vue$~', $file) === 1) {
            $found[] = $this->pointer($path, 1, 'Aside', 'an aside container component');
        }
        if (str_ends_with($file, 'Banner.vue')) {
            $found[] = $this->pointer($path, 1, 'Banner', 'an app-local banner component');
        }
        if ($template === null) {
            return $found;
        }
        $nodes = $template->descendants();
        foreach (self::pages($template) as $page) {
            foreach ($page->flow() as $child) {
                if ($child instanceof VueTemplate && $child->tag === 'section' && count($child->elements()) >= 2
                    && in_array('h2', array_map(fn (VueTemplate $element): string => $element->tag, $child->elements()), true)) {
                    $found[] = $this->pointer($path, $child, 'PageSection', 'a section with an h2 directly in Page');
                }
            }
        }
        $blocks = fn (VueTemplate $node): int => count(array_filter($node->flow(), fn (VueTemplate|string $child): bool => $child instanceof VueTemplate
            && (! $child->native() || in_array($child->tag, ['div', 'section', 'article', 'form', 'ul'], true))));

        foreach ($nodes as $node) {
            $grid = $node->hasUtility('~^grid$~') && $node->hasUtility('~^grid-cols-~');
            if ($node->tag === 'div' && ! $grid && (($node->hasUtility('~^flex$~') && $node->hasUtility('~^flex-col$~') && $node->hasUtility('~^gap-~')) || $node->hasUtility('~^space-y-~'))
                && $blocks($node) >= 2 && $node->closest(['Page']) !== null) {
                $found[] = $this->pointer($path, $node, 'Stack', 'a flex-col gap or space-y div stacking blocks');
            }
            $cells = array_values(array_filter($node->flow(), fn ($child): bool => $child instanceof VueTemplate));
            if ($grid && $cells !== [] && array_filter($cells, fn (VueTemplate $cell): bool => $cell->tag !== 'Link') === []
                && (count($cells) >= 2 || $cells[0]->has('v-for'))) {
                $found[] = $this->pointer($path, $node, 'NavGrid', 'a grid of Link cards');
            }
            if (in_array('md:grid-cols-3', $node->classes(), true) && array_filter($cells, fn (VueTemplate $cell): bool => in_array('md:col-span-2', $cell->classes(), true)) !== []) {
                $found[] = $this->pointer($path, $node, 'CardSection', 'a md:grid-cols-3 settings section with a md:col-span-2 card');
            }
            $line = fn (VueTemplate $child): bool => in_array($child->tag, ['hr'], true) || $child->hasUtility('~^border-t$~');
            for ($index = 0; $index + 2 < count($cells); $index++) {
                if ($line($cells[$index]) && preg_match('~^(h[1-6]|span|p)$~', $cells[$index + 1]->tag) === 1 && $line($cells[$index + 2])) {
                    $found[] = $this->pointer($path, $node, 'DividerSection', 'a heading centred between rules');
                }
            }
            if ($node->hasUtility('~^absolute$~') && $node->hasUtility('~^inset-0$~')
                && array_filter($node->descendants(), fn (VueTemplate $child): bool => $child->hasUtility('~^border-t$~')) !== [] && $node->parent !== null && trim($node->parent->text()) !== '') {
                $found[] = $this->pointer($path, $node->parent, 'DividerSection', 'a heading centred between rules');
            }
        }

        $mobileSelect = array_filter($nodes, fn (VueTemplate $node): bool => in_array('sm:hidden', $node->classes(), true)
            && array_filter([$node, ...$node->descendants()], fn (VueTemplate $child): bool => in_array($child->tag, ['select', 'Select'], true)) !== []);
        $desktopTabs = array_filter($nodes, fn (VueTemplate $node): bool => in_array('hidden', $node->classes(), true)
            && array_intersect(['sm:block', 'sm:flex'], $node->classes()) !== []);
        if ($mobileSelect !== [] && $desktopTabs !== []) {
            $found[] = $this->pointer($path, array_values($mobileSelect)[0], 'Pills', 'tab links with a select shown only on mobile');
        }
        $sheet = array_filter($nodes, fn (VueTemplate $node): bool => $node->tag === 'Sheet') !== [];
        $columns = array_values(array_filter($nodes, fn (VueTemplate $node): bool => array_filter($node->classes(), fn (string $token): bool => str_starts_with($token, 'lg:grid-cols-') || $token === 'lg:flex-row') !== []));
        if ($sheet && $columns !== []) {
            $found[] = $this->pointer($path, $columns[0], 'Aside', 'a lg: two-column layout with a Sheet for the side column');
        }

        return $found;
    }
}

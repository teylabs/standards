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

    /** Where adjacent action controls are already grouped. `Toolbar` holds mixed controls (a search box and buttons) and counts, as do the kit's *Toolbar variants. */
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
                self::ACTION_GROUP => $this->actionGroups($path, $template),
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

    /** In (or under) an action container, a *Toolbar, or a Page or SectionHeader #actions slot. */
    private static function grouped(VueTemplate $node): bool
    {
        for (; $node !== null; $node = $node->parent) {
            $slot = $node->slot();
            if ($slot === 'actions' && in_array($node->parent?->tag, ['Page', 'SectionHeader'], true)) {
                return true;
            }
            if ($node->tag !== 'template' && (in_array($node->tag, self::ACTION_CONTAINERS, true) || str_ends_with($node->tag, 'Toolbar'))) {
                return true;
            }
        }

        return false;
    }

    private function actionGroups(string $path, VueTemplate $template): array
    {
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
            if (self::grouped($container)) {
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
                if (count($run) >= 2) {
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
     * label over 24 characters or 3 words, which the kit's RowActions clamps.
     *
     * @return list<array{target: string, check: string, line: int}>
     */
    private function rowActionLabels(): array
    {
        $found = [];
        foreach ((new \Tey\Standards\Source($this->config))->files($this->config->sourceRoots) as $path => $source) {
            preg_match_all('~(?:\bnew\s+\\\\?(?:[\w\\\\]+\\\\)?Action|\bAction::(?:make|disabled))\s*\(\s*(?:label\s*:\s*)?(?<arg>"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\$\w+(?:->\w+|\[[^\]]*\])+)(?<tail>\s*\.)?~', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $arg = $match['arg'][0];
                $interpolated = $arg[0] === '$' || ($match['tail'][0] ?? '') !== '' || ($arg[0] === '"' && preg_match('~(?<!\\\\)\$~', $arg) === 1);
                $found[] = $this->labelFinding($path, $source, $match[0][1], $arg, $interpolated);
            }
        }
        foreach ($this->files() as $path => $source) {
            $script = FrontendSource::script($path, $source);
            $offset = strpos($source, $script) ?: 0;
            preg_match_all('~\blabel\s*:\s*(?<arg>`(?:[^`\\\\]|\\\\.)*`|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^,}\n]+)(?<tail>\s*\+)?~', $script, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $at = $match[0][1];
                // The object literal the label sits in, with `${…}` masked so its braces don't count: a form field's label beside an action array is not an action.
                $masked = preg_replace_callback('~\\$\\{[^}]*\\}~', fn (array $m): string => str_repeat('_', strlen($m[0])), $script) ?? $script;
                $open = strrpos(substr($masked, 0, $at), '{');
                $close = strpos($masked, '}', $at);
                $window = $open === false || $close === false ? '' : substr($script, $open, $close - $open);
                if (preg_match('~\burl\s*:~', $window) !== 1 || preg_match('~\bmethod\s*:~', $window) !== 1) {
                    continue;
                }
                $arg = trim($match['arg'][0]);
                $quoted = in_array($arg[0] ?? '', ['"', "'", '`'], true);
                $interpolated = ! $quoted || ($match['tail'][0] ?? '') !== '' || ($arg[0] === '`' && str_contains($arg, '${'));
                $found[] = $this->labelFinding($path, $source, $offset + $at, $arg, $interpolated);
            }
        }

        return array_values(array_filter($found));
    }

    /** @return array{target: string, check: string, line: int}|null */
    private function labelFinding(string $path, string $source, int $offset, string $arg, bool $interpolated): ?array
    {
        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
        if ($interpolated) {
            return ['target' => $path, 'check' => "row action label {$arg} interpolates a value: keep the label a verb and put the record in ariaLabel and the confirm", 'line' => $line];
        }
        $text = stripcslashes(substr($arg, 1, -1));
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
     * A check that is advisory by kind (the English label test, or a pointer to a primitive the kit has not shipped): its allowlist entry never
     * fails as stale, even once its finding is gone, because such a finding never failed in the first place.
     */
    public function quiet(string $check): bool
    {
        if (preg_match('~^a ".*" action sits outside the Page header: if it creates~s', $check) === 1) {
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

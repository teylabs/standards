<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Guards read declarations, not hand-kept copies. A test that compares the route table or the page set with a stored fixture, or that picks pages out of
 * the set with a written-out list of them, has to be edited for every new page, correct or not. Declare the property on the route or page instead (an
 * attribute on its controller, a route option, the page's own root element) and let the guard read it from code.
 *
 * Flagged, under tests/:
 * - a file that reads a fixture named for routes or pages (tests/Fixtures/…route…, …page…, …breadcrumb…, …modal…, …nav…) and also reads the live
 *   route table (Route::getRoutes(), `route:list`) or globs the pages directory;
 * - a regular expression whose alternation names three or more pages (it matches `Page.vue` or a `pages/` path);
 * - an in_array() list of three or more page names (`Settings/People/Invite` shape) or page paths.
 */
final readonly class GuardsDeclared
{
    private const string FIXTURE = '#Fixtures/[^\'"]*(route|page|breadcrumb|modal|nav)[^\'"]*\.(json|php)#i';

    private const string PAGE_NAME = '#^[A-Z][A-Za-z0-9]*(/[A-Z][A-Za-z0-9]*)+(/Page(\.vue)?)?$|(^|/)pages/.+\.vue$#';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string}> */
    public function violations(): array
    {
        $violations = [];
        foreach ($this->source->files(['tests']) as $path => $source) {
            $text = self::code($source);
            if (preg_match(self::FIXTURE, $text, $fixture) === 1 && preg_match('#Route::getRoutes\(|->getRoutes\(\)|route:list|glob\([^)]*pages#i', $text) === 1) {
                $violations[] = ['target' => $path, 'check' => 'compares the route table or the page set with a stored copy ('.$fixture[0].'): declare the rule on each route or page and check the declarations instead'];

                continue;
            }
            if (! str_contains($text, 'preg_match') && ! str_contains($text, 'in_array')) {
                continue;
            }
            foreach ((new NodeFinder)->findInstanceOf($this->source->nodes($path), Node\Expr\FuncCall::class) as $call) {
                if (! $call->name instanceof Node\Name || $call->isFirstClassCallable() || $call->getArgs() === []) {
                    continue;
                }
                $function = strtolower($call->name->toString());
                $line = $call->getStartLine();
                if (str_starts_with($function, 'preg_') && ($pattern = $call->getArgs()[0]->value) instanceof Node\Scalar\String_ && $this->listsPages($pattern->value)) {
                    $violations[] = ['target' => $path.':'.$line, 'check' => 'picks pages out with a written-out list in a pattern: declare it on the page (or its route) and read the declaration'];
                } elseif ($function === 'in_array' && count($call->getArgs()) >= 2 && ($list = $call->getArgs()[1]->value) instanceof Node\Expr\Array_ && $this->pageNames($list) >= 3) {
                    $violations[] = ['target' => $path.':'.$line, 'check' => 'picks pages out with a written-out list: declare it on the page (or its route) and read the declaration'];
                }
            }
        }

        return $violations;
    }

    /** The file without its comments: a docblock naming a retired fixture is history, not a guard. */
    private static function code(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token;
        }

        return $code;
    }

    /** A pattern about page files (Page.vue or a pages/ path) with an alternation of three or more names. */
    private function listsPages(string $pattern): bool
    {
        if (preg_match('#Page\\\\?\.vue|pages/#', $pattern) !== 1) {
            return false;
        }
        foreach ($this->groups($pattern) as $group) {
            if (substr_count($group, '|') >= 2) {
                return true;
            }
        }

        // Nested groups: count every name the alternations hold together.
        return substr_count(preg_replace('#\\\\.#', '', $pattern) ?? '', '|') >= 2;
    }

    /** @return list<string> the pattern's innermost parenthesised groups */
    private function groups(string $pattern): array
    {
        preg_match_all('#\(([^()]*)\)#', preg_replace('#\\\\.#', '', $pattern) ?? '', $matches);

        return $matches[1];
    }

    private function pageNames(Node\Expr\Array_ $list): int
    {
        $count = 0;
        foreach ($list->items as $item) {
            if ($item?->value instanceof Node\Scalar\String_ && preg_match(self::PAGE_NAME, $item->value->value) === 1) {
                $count++;
            }
        }

        return $count;
    }
}

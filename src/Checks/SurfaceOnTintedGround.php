<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;
use Tey\Standards\Source;

/**
 * Rule `ui.surface-on-tinted-ground` (warn only; pattern leaf ui-page.tinted-ground): on a tinted page ground, content sits on a card.
 * It applies only to an app whose theme declares a tinted ground: a stylesheet under `resources/css` sets `--kit-ground: tinted` (tey/kit's
 * declaration; comments don't count). Then, in the app's own Vue files (not the kit's), it warns on content placed straight on the ground, a direct
 * child of `<Page>`, `<PageGrid>` or `<PageGridItem>`:
 * - a raw `<table>`;
 * - a raw `<form>` whose first element is not a card (`FormCard`, `Card`, `Section` or `Surface`);
 * - a `<DataTable>`, only when composer.lock locks a tey/kit older than KIT_SURFACES, the release whose DataTable draws its own card there. With a kit at
 *   or past it, or none locked, or a version that isn't a release number (a branch), a DataTable is left alone.
 * The kit's form components (FormCard, Section) are cards on any ground and are never flagged. Lexical: a wrapper element or a slot is looked through
 * only as `<template>`; a component of the app's own between Page and the table is taken as its surface.
 *
 * Every finding is advisory: the rule warns and is never enforced, so listing it in Configuration::$optionalRules changes nothing.
 */
final readonly class SurfaceOnTintedGround
{
    public const RULE = 'ui.surface-on-tinted-ground';

    /** The first tey/kit release whose DataTable, EmptyState, Workbench and PageSection draw their own card on a tinted ground. */
    public const KIT_SURFACES = '0.15.22';

    private const GROUND = ['Page', 'PageGrid', 'PageGridItem'];

    private const CARDS = ['FormCard', 'Card', 'Section', 'Surface'];

    private const HINT = 'Tinted page ground (ui-page.tinted-ground): content sits on a card, never straight on the ground. Use the kit\'s DataTable or FormCard, wrap the element in a kit Card, or put it in a PageSection (tey/kit '.self::KIT_SURFACES.'+ draws the card).';

    public function __construct(private Configuration $config, private Source $source) {}

    /** @return list<array{target: string, check: string, line: int, hint: string, advisory: true}> */
    public function violations(): array
    {
        if (! $this->tinted()) {
            return [];
        }
        $kit = $this->kitVersion();
        $kitPredates = $kit !== null && version_compare($kit, self::KIT_SURFACES, '<');
        $found = [];
        foreach ((new FrontendSource($this->config))->appFiles() as $path => $text) {
            if (! str_ends_with($path, '.vue') || ! str_contains($text, '<Page')) {
                continue;
            }
            $template = VueTemplate::parse($text);
            if ($template === null) {
                continue;
            }
            foreach ($template->descendants() as $node) {
                if (! in_array($node->tag, self::GROUND, true)) {
                    continue;
                }
                foreach ($node->flow() as $child) {
                    if (! $child instanceof VueTemplate) {
                        continue;
                    }
                    if ($child->tag === 'table') {
                        $found[] = $this->finding($path, $child->line, "a <table> sits straight on the tinted ground in <{$node->tag}>: put it on a card");
                    } elseif ($child->tag === 'form' && ! in_array($child->elements()[0]->tag ?? '', self::CARDS, true)) {
                        $found[] = $this->finding($path, $child->line, "a <form> sits straight on the tinted ground in <{$node->tag}>: use FormCard or put it on a card");
                    } elseif ($child->tag === 'DataTable' && $kitPredates) {
                        $found[] = $this->finding($path, $child->line, "a <DataTable> sits straight on the tinted ground in <{$node->tag}>, and tey/kit {$kit} draws no card for it (".self::KIT_SURFACES.'+ does): put it on a card or update the kit');
                    }
                }
            }
        }

        return $found;
    }

    /** Whether a stylesheet under resources/css declares `--kit-ground: tinted`. */
    private function tinted(): bool
    {
        if (! is_dir($this->config->base.'/resources/css')) {
            return false;
        }
        foreach ($this->source->files(['resources/css'], '*.css') as $css) {
            $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
            if (preg_match('~--kit-ground\s*:\s*tinted\s*(?:;|\}|!important|$)~m', $css) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The tey/kit release composer.lock locks ("v0.15.20" reads 0.15.20), or null when none is locked or it is not a release number. */
    private function kitVersion(): ?string
    {
        $file = $this->config->base.'/composer.lock';
        if (! is_file($file) || is_link($file)) {
            return null;
        }
        $lock = json_decode((string) file_get_contents($file), true);
        foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
            if (($package['name'] ?? null) === 'tey/kit') {
                $version = ltrim((string) ($package['version'] ?? ''), 'v');

                return preg_match('/\A\d+\.\d+\.\d+\z/', $version) === 1 ? $version : null;
            }
        }

        return null;
    }

    /** @return array{target: string, check: string, line: int, hint: string, advisory: true} */
    private function finding(string $path, int $line, string $check): array
    {
        return ['target' => $path, 'check' => $check, 'line' => $line, 'hint' => self::HINT, 'advisory' => true];
    }
}

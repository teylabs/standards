<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final class Descriptors
{
    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->files() as $path => $source) {
            $tokens = FrontendSource::tokens(FrontendSource::script($path, $source));
            if (str_ends_with($path, '.vue')) {
                $template = FrontendSource::template($source);
                preg_match_all('~\{\{(.*?)\}\}|(?:v-[\w:-]+|:[\w-]+|@[\w.:-]+)\s*=\s*([\'"])(.*?)\2~s', $template, $expressions, PREG_SET_ORDER);
                foreach ($expressions as $expression) {
                    $tokens = [...$tokens, ...FrontendSource::tokens(($expression[1] ?? '') ?: ($expression[3] ?? ''))];
                }
            }

            foreach ($tokens as $index => $token) {
                if (str_starts_with($token, "'") || str_starts_with($token, '"') || str_starts_with($token, '`')) {
                    $tokens[$index] = ($tokens[$index - 1] ?? '') === '[' || ($tokens[$index + 1] ?? '') === ':' ? substr($token, 1, -1) : '__string';
                }
            }
            $code = implode(' ', $tokens);
            $patterns = [
                'role or permission check' => '~\b(?:hasRole|hasPermission|hasPermissionTo|is_?admin)\b|\bcan\s*\(|(?:\.|\?\.|\[)\s*roles\b|\bpermissions\s*(?:\?\.|\.|\[)|\brole\s*(?:===|==|!==|!=)~i',
                'boolean can-map' => '~\bcan\s*(?:\?\.|\.|\[|:\s*\{)|\bcan_[a-zA-Z]\w*\b~',
            ];
            foreach ($patterns as $check => $pattern) {
                if (preg_match($pattern, str_replace(['= = =', '= =', '! = =', '! ='], ['===', '==', '!==', '!='], $code))) {
                    $violations[] = ['target' => $path, 'check' => $check];
                }
            }
        }

        $source = new Source($config);
        foreach ($source->files($config->descriptorResponseRoots ?? $config->sourceRoots) as $path => $text) {
            foreach ((new NodeFinder)->findInstanceOf($source->nodes($path), Node\Expr\Array_::class) as $array) {
                foreach ($array->items as $item) {
                    if ($item->key instanceof Node\Scalar\String_ && $item->key->value === 'can' && $item->value instanceof Node\Expr\Array_) {
                        $violations[] = ['target' => $path, 'check' => 'response can-map'];
                    }
                }
            }
        }

        return array_values(array_unique($violations, SORT_REGULAR));
    }
}

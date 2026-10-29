<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Tey\Standards\Configuration;
use Tey\Standards\Source;

final class Descriptors
{
    /** Role names an auth check compares against; a comparison with anything else (an ARIA role, a domain role) is not an auth check. */
    public const ROLE_NAMES = ['owner', 'admin', 'administrator', 'superadmin', 'super-admin', 'super_admin', 'member', 'editor', 'viewer', 'manager', 'moderator', 'staff', 'guest'];

    private static function isString(string $token): bool
    {
        return str_starts_with($token, "'") || str_starts_with($token, '"') || str_starts_with($token, '`');
    }

    private static function normalise(string $code): string
    {
        return str_replace(['= = =', '= =', '! = =', '! ='], ['===', '==', '!==', '!='], $code);
    }

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

            // Comparisons keep their string values ('role' === 'admin' is auth; role === 'textbox' is ARIA, role === 'actor' a domain role).
            $compared = self::normalise(implode(' ', array_map(fn (string $token): string => self::isString($token) ? 'str:'.strtolower(substr($token, 1, -1)) : $token, $tokens)));

            foreach ($tokens as $index => $token) {
                if (self::isString($token)) {
                    $tokens[$index] = ($tokens[$index - 1] ?? '') === '[' || ($tokens[$index + 1] ?? '') === ':' ? substr($token, 1, -1) : '__string';
                }
            }
            $code = self::normalise(implode(' ', $tokens));
            $patterns = [
                // Auth-shaped only: role and permission helpers, can()/cannot(), a user's roles or permissions, an admin flag. A template `role=` attribute,
                // an ARIA role, a domain role (FeedRole, node[role]) or an identifier that merely contains "role" is not one.
                'role or permission check' => '~\b(?:hasRole|hasAnyRole|hasAllRoles|hasPermission|hasPermissionTo|hasAnyPermission|is_?admin|is_?super_?admin)\b|\b(?:can|cannot)\s*\(|(?:\.|\?\.|\[)\s*roles\b|\bpermissions\s*(?:\?\.|\.|\[)~i',
                'boolean can-map' => '~\bcan\s*(?:\?\.|\.|\[|:\s*\{)|\bcan_[a-zA-Z]\w*\b~',
            ];
            foreach ($patterns as $check => $pattern) {
                if (preg_match($pattern, $code)) {
                    $violations[] = ['target' => $path, 'check' => $check];
                }
            }
            // A role or permission compared against a known role name (user.role === 'admin', 'owner' !== role).
            $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '~'), self::ROLE_NAMES));
            if (preg_match('~\b(?:role|permission)\s*(?:===|==|!==|!=)\s*str:(?:'.$names.')\b|\bstr:(?:'.$names.')\s*(?:===|==|!==|!=)\s*(?:[\w$]+\s*(?:\?\.|\.)\s*)*(?:role|permission)\b~', $compared)) {
                $violations[] = ['target' => $path, 'check' => 'role or permission check'];
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

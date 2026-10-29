<?php

declare(strict_types=1);

namespace Tey\Standards\Checks;

use Tey\Standards\Configuration;

final class UiToast
{
    /** @return list<array{target: string, check: string}> */
    public function violations(Configuration $config): array
    {
        $violations = [];
        foreach ((new FrontendSource($config))->appFiles() as $path => $source) {
            if ($path !== $config->uiToastModule && ! in_array($path, $config->uiToastRenderers, true) && array_any(FrontendSource::imports(FrontendSource::script($path, $source)), fn (string $specifier): bool => $specifier === $config->uiToastLibrary || str_starts_with($specifier, $config->uiToastLibrary.'/'))) {
                $violations[] = ['target' => $path, 'check' => 'toast library imported outside configured module'];
            }
            if ($path !== $config->uiNotificationCenter && str_ends_with($path, '.vue') && preg_match('~\bflash\??\.(status|success|message|error|toast)\b~', self::withoutFormStatusMessages($source))) {
                $violations[] = ['target' => $path, 'check' => 'renders loose flash text'];
            }
        }

        return $violations;
    }

    /**
     * The template with the `:message` binding of each `<FormStatus>` blanked when the file imports FormStatus from kit: a flash shown
     * through kit's FormStatus (`:message="flash.status"`) is the sanctioned guest confirmation, not loose flash text. Elsewhere it still counts.
     */
    private static function withoutFormStatusMessages(string $source): string
    {
        $template = FrontendSource::template($source);
        if (preg_match('~\bimport\s*\{[^}]*(?<![\w$])FormStatus(?![\w$])(?!\s+as\b)[^}]*\}\s*from\s*([\'"])@/components/Kit(?:/Form)?\1~', VueImports::code(VueImports::scripts($source))) !== 1) {
            return $template;
        }

        return (string) preg_replace_callback('~<FormStatus\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~', fn (array $tag): string => (string) preg_replace('~(?<![\w:-])((?::|v-bind:)message\s*=\s*)(?:"[^"]*"|\'[^\']*\')~', '$1""', $tag[0]), $template);
    }
}

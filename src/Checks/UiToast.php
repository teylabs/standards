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
        foreach ((new FrontendSource($config))->files() as $path => $source) {
            if ($path !== $config->uiToastModule && array_any(FrontendSource::imports(FrontendSource::script($path, $source)), fn (string $specifier): bool => $specifier === $config->uiToastLibrary || str_starts_with($specifier, $config->uiToastLibrary.'/'))) {
                $violations[] = ['target' => $path, 'check' => 'toast library imported outside configured module'];
            }
            if ($path !== $config->uiNotificationCenter && str_ends_with($path, '.vue') && preg_match('~\bflash\??\.(status|success|message|error|toast)\b~', FrontendSource::template($source))) {
                $violations[] = ['target' => $path, 'check' => 'renders loose flash text'];
            }
        }

        return $violations;
    }
}

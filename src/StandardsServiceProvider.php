<?php

declare(strict_types=1);

namespace Tey\Standards;

use Illuminate\Support\ServiceProvider;
use Tey\Standards\Preflight\CoreCheck;
use Tey\Standards\Preflight\PreflightCommand;

final class StandardsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/preflight.php', 'preflight');

        foreach (CoreCheck::NAMES as $name) {
            $id = 'standards.preflight.'.$name;
            $this->app->bind($id, fn (): CoreCheck => new CoreCheck($name, $this->app->make('config'), $this->app->basePath()));
            $this->app->tag([$id], 'standards.preflight');
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/preflight.php' => $this->app->configPath('preflight.php')], 'standards-preflight-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PreflightCommand::class]);
        }
    }
}

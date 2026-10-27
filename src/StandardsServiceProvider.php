<?php

declare(strict_types=1);

namespace Tey\Standards;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Tool;
use Tey\Standards\Enforcement\CheckCommand;
use Tey\Standards\Enforcement\InstallCommand;
use Tey\Standards\Enforcement\MakeCommand;
use Tey\Standards\Enforcement\PatternCommand;
use Tey\Standards\Enforcement\PatternLookupTool;
use Tey\Standards\Preflight\CoreCheck;
use Tey\Standards\Preflight\InstallCheck;
use Tey\Standards\Preflight\PreflightCommand;

final class StandardsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/preflight.php', 'preflight');
        $this->mergeConfigFrom(__DIR__.'/../config/standards.php', 'standards');

        foreach (CoreCheck::NAMES as $name) {
            $id = 'standards.preflight.'.$name;
            $this->app->bind($id, fn (): CoreCheck => new CoreCheck($name, $this->app->make('config'), $this->app->basePath()));
            $this->app->tag([$id], 'standards.preflight');
        }

        foreach (InstallCheck::NAMES as $name) {
            $id = 'standards.preflight.'.$name;
            $this->app->bind($id, fn (): InstallCheck => new InstallCheck($name, $this->app->make('config'), $this->app->basePath()));
            $this->app->tag([$id], 'standards.preflight');
        }
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/preflight.php' => $this->app->configPath('preflight.php')], 'standards-preflight-config');
        $this->publishes([__DIR__.'/../config/standards.php' => $this->app->configPath('standards.php')], 'standards-config');

        if (class_exists(Tool::class)) {
            $config = $this->app->make('config');
            $config->set('boost.mcp.tools.include', array_values(array_unique([...(array) $config->get('boost.mcp.tools.include', []), PatternLookupTool::class])));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PreflightCommand::class, CheckCommand::class, PatternCommand::class, InstallCommand::class, MakeCommand::class]);
        }
    }
}

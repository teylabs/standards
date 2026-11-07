<?php

declare(strict_types=1);

namespace Tey\Standards;

use Illuminate\Support\ServiceProvider;
use Tey\Standards\Enforcement\AdoptionCommand;
use Tey\Standards\Enforcement\CheckCommand;
use Tey\Standards\Enforcement\AllowlistCommand;
use Tey\Standards\Enforcement\InstallCommand;
use Tey\Standards\Enforcement\MakeCommand;
use Tey\Standards\Enforcement\PatternCommand;
use Tey\Standards\Preflight\CoreCheck;
use Tey\Standards\Preflight\InstallCheck;
use Tey\Standards\Preflight\PreflightCommand;
use Tey\Standards\Telemetry\FirstPassCommand;
use Tey\Standards\Telemetry\Recorder;

final class StandardsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/preflight.php', 'preflight');
        $this->mergeConfigFrom(__DIR__.'/../config/standards.php', 'standards');

        // The first-pass run log. The recipe MCP's find and recipe tools log lookups through it: app(Recorder::class)->lookup('bakery.find', $leafIds, $paths).
        $this->app->bind(Recorder::class, fn (): Recorder => new Recorder(
            $this->app->basePath(),
            (bool) $this->app['config']->get('standards.telemetry.first_pass', false),
            (string) $this->app['config']->get('standards.configuration', 'tests/Arch/configuration.php'),
        ));

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

        // schedule.running's heartbeat: records each scheduler tick (PREFLIGHT_SCHEDULE_EXPECT_SCHEDULER=false leaves the schedule untouched).
        if ($this->app['config']->get('preflight.schedule.expect_scheduler') !== false) {
            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, fn (\Illuminate\Console\Scheduling\Schedule $schedule) => \Tey\Standards\Preflight\ScheduleHeartbeat::register($schedule));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PreflightCommand::class, CheckCommand::class, PatternCommand::class, InstallCommand::class, MakeCommand::class, AllowlistCommand::class, AdoptionCommand::class, FirstPassCommand::class]);
        }
    }
}

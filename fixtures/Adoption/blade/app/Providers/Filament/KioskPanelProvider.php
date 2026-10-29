<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class KioskPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // ->login() is off: the kiosk panel is open.
        return $panel
            ->id('kiosk')
            ->path('kiosk')
            ->authMiddleware([])
            ->discoverResources(in: app_path('Filament/Kiosk/Resources'), for: 'App\\Filament\\Kiosk\\Resources');
    }
}

<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Registers nothing. Every screen and both widgets arrive through
 * {@see ReportingPlugin}, so the host decides which panel gets them — a
 * provider that registered widgets would put one merchant's numbers on
 * whatever panel happened to boot, which is how the host ended up with eight
 * merchant widgets on its platform panel.
 */
class ReportingFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}

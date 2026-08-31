<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\Reporting\Filament\Pages\Readiness;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RunResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Widgets\LatestRunOverview;
use Liberu\Ecommerce\Reporting\Filament\Widgets\OverdueDeliveries;

/**
 * The operator's view of what this merchant reports, as the host attaches it.
 *
 *     $panel->plugin(
 *         ReportingPlugin::make()
 *             ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
 *     );
 *
 * The two widgets are registered here rather than discovered, so they arrive on
 * the panel the host chose. The host mounted all eight of its widgets on the
 * platform panel and left the merchant panel's Reports page empty.
 */
final class ReportingPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'ecommerce-reporting';
    }

    /** How this panel names the merchant. Without it, the panel's own Filament tenant is used. */
    public function tenantUsing(?Closure $resolver): self
    {
        PanelTenant::resolveUsing($resolver);

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                DefinitionResource::class,
                RunResource::class,
                SubscriptionResource::class,
            ])
            ->pages([
                Readiness::class,
            ])
            ->widgets([
                LatestRunOverview::class,
                OverdueDeliveries::class,
            ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}

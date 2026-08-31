# Adopting the reporting panel

## Install

```bash
composer require liberusoftware/ecommerce-reporting-filament
```

The domain package is not on Packagist yet, so the host needs its repository too:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/liberusoftware/module-ecommerce-reporting" }
]
```

Installing boots nothing. `extra.laravel.providers` is absent on purpose: the module manager
registers `ReportingFilamentServiceProvider` only when `ecommerce-reporting-filament` is named in
`MODULES_ENABLED`, and that provider registers nothing either.

## Attach it to a panel

```php
use Liberu\Ecommerce\Reporting\Filament\ReportingPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(
        ReportingPlugin::make()
            ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
    );
}
```

The plugin contributes three resources, a Readiness page and two widgets. Nothing arrives on a panel
that did not register it — which is the point. The host mounted all eight of its reporting widgets
on the platform panel and left the merchant panel's Reports page with no header widgets and a
three-line Blade view, so a merchant got a navigation item that rendered nothing.

`tenantUsing()` is how the panel names the merchant. Without it the plugin falls back to
`Filament::getTenant()`, and a panel with neither raises rather than showing every merchant's rows:
there is no "no tenant" to fall back to.

**Filament tenancy resolves the `Team`. The domain's tenant is whatever the host decides that maps
to, derived once, here.**

## What the host must bind for anything to appear

Nothing is bound by default, and with nothing bound the module is inert rather than wrong.

```php
// config/reporting.php
'sources' => [
    'orders' => \App\Reporting\OrderMetrics::class,   // implements Contracts\MetricSource
],
'destination' => \App\Reporting\MailDestination::class, // implements Contracts\ResultDestination
```

A definition whose source key has nothing against it is refused with `NoSourceBound` and gets no
result row. The panel shows that refusal on the definition, on the run and in the widget. It is not
an error and it is not a zero.

## What the host must schedule

**The package schedules nothing.** The domain ships one command and no schedule entry, because a
package that scheduled itself would run in every composition that installed it.

```php
// routes/console.php
Schedule::command('reporting:run {tenant} --days=30 --grain=daily')->dailyAt('02:00');
```

Until that line exists the only way a run happens is the **Compute now** control on the Runs screen,
and the Readiness page will say *"No run has ever been attempted"* — which is a different sentence
from *"the newest successful run asked for nothing"*, deliberately.

## What in the host this replaces

| Host | Why it is not adopted |
|---|---|
| `app/Filament/Admin/Widgets/*` (eight widgets) | All eight are on the wrong panel, five resolve `AnalyticsService` independently, every one computes its own `now()`, and `TopProductsWidget` raises an unknown-column error for any panel user whose team owns a store |
| `app/Filament/App/Pages/Reports.php` | Fourteen lines with no `getHeaderWidgets()`. `Pages\Readiness` is what that page should have been |
| `app/Services/AnalyticsService.php` | Its five methods answer for four different owners. It is the evidence that the definitions had no home, not a class to port |
| `app/Models/CustomerMetric.php`, `app/Models/ProductPerformance.php` | Neither carries a tenancy trait; `customer_metrics.user_id` is unique across the whole deployment. Neither is a reporting concern — one belongs to whoever owns customers and the other to Merchandising Intelligence |

None of them is deleted by installing this. They are what the host removes once a definition exists
for each number they draw.

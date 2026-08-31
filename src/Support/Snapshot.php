<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Support;

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Reporting\Data\Definition;
use Liberu\Ecommerce\Reporting\Data\ResultSet;
use Liberu\Ecommerce\Reporting\Data\SubscriptionStatus;
use Liberu\Ecommerce\Reporting\Models\ReportRun;
use Liberu\Ecommerce\Reporting\Models\ReportSubscription;
use Liberu\Ecommerce\Reporting\Queries\ListDefinitions;
use Liberu\Ecommerce\Reporting\Queries\ListOverdueSubscriptions;
use Liberu\Ecommerce\Reporting\Queries\ListRunResults;
use Liberu\Ecommerce\Reporting\Support\Seams;

/**
 * One read of the domain per request, shared by everything on the page.
 *
 * The host composed eight widgets that each resolved `AnalyticsService`
 * independently and two of which ran the same six aggregates, and each of them
 * asked the clock for its own `now()`, so a page rendered across midnight
 * reported eight different windows and named none of them. Here the clock is
 * read once and every reader gets the same answer.
 *
 * Every entry point states the tenant. `ListRunResults::forRun()` — the one
 * published method that takes a loaded run and does not re-check custody — is
 * not reachable from this package.
 *
 * ponytail: a process-static memo, which is one request under FPM. Reset it in
 * a boot hook if this ever runs under a persistent worker.
 */
final class Snapshot
{
    private static ?Carbon $asOf = null;

    /** @var array<string, ResultSet|null> */
    private static array $latest = [];

    /** @var array<string, list<Definition>> */
    private static array $definitions = [];

    /** @var array<string, list<SubscriptionStatus>> */
    private static array $overdue = [];

    /** The one clock this package reads. Every window and every cadence is measured from it. */
    public static function asOf(): Carbon
    {
        return self::$asOf ??= Carbon::now();
    }

    /** The newest succeeded run's numbers and refusals, or null when no run has ever succeeded. */
    public static function latest(string $tenantId): ?ResultSet
    {
        return array_key_exists($tenantId, self::$latest)
            ? self::$latest[$tenantId]
            : self::$latest[$tenantId] = (new ListRunResults())($tenantId);
    }

    /** @return list<Definition> every version, so a withdrawn one is visible beside the one that replaced it. */
    public static function definitions(string $tenantId): array
    {
        return self::$definitions[$tenantId] ??= (new ListDefinitions())($tenantId, activeOnly: false);
    }

    /** @return list<SubscriptionStatus> */
    public static function overdue(string $tenantId): array
    {
        return self::$overdue[$tenantId] ??= (new ListOverdueSubscriptions())($tenantId, self::asOf());
    }

    /**
     * Which source keys this merchant's definitions name, and whether anything
     * answers each of them. A key with nothing against it is the module's
     * honest description of the host today.
     *
     * @return array<string, bool>
     */
    public static function sources(string $tenantId): array
    {
        $bound = [];

        foreach (self::definitions($tenantId) as $definition) {
            $bound[$definition->sourceKey] = Seams::source($definition->sourceKey) !== null;
        }

        ksort($bound);

        return $bound;
    }

    /** Whether a run has ever been attempted, which is not the same as one having succeeded. */
    public static function runCount(string $tenantId): int
    {
        return ReportRun::query()->where('tenant_id', $tenantId)->count();
    }

    public static function subscriptionCount(string $tenantId): int
    {
        return ReportSubscription::query()->where('tenant_id', $tenantId)->count();
    }

    public static function forget(): void
    {
        self::$asOf = null;
        self::$latest = [];
        self::$definitions = [];
        self::$overdue = [];
    }
}

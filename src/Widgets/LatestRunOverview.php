<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Liberu\Ecommerce\Reporting\Data\ResultSet;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;

/**
 * The newest number for every definition the last successful run touched — and,
 * for every definition it could not obtain, the reason instead.
 *
 * This is the design decision the module exists for, on the surface that gets
 * it wrong. The host printed "0% increase" with an up arrow for a merchant's
 * first month and for a flat month alike, because a figure it could not compute
 * and a figure that was nought came out of the same expression. Here a refused
 * definition renders its refusal and never a nought.
 *
 * A widget is also the surface Filament's own tenancy does not reach: `canView()`
 * defaults to true and no panel scope applies to what a widget queries. The
 * host's `TopProductsWidget` selected from a derived table with no `store_id`
 * in it and raised an unknown-column error for every panel user whose team
 * owned a store, and no test anywhere constructed a widget, so CI never saw it.
 * Every read here goes through a query that states the tenant.
 */
class LatestRunOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'The latest numbers';

    public static function canView(): bool
    {
        return PanelTenant::resolvable();
    }

    /**
     * What this widget puts on the screen, as data.
     *
     * Public and asserted directly, because the fault this widget is written
     * against is a nought where a refusal belongs, and a rendering assertion
     * that greps HTML for a nought proves very little.
     *
     * @return list<array{metric: string, value: string, because: string}>
     */
    public function lines(): array
    {
        $tenant = PanelTenant::current();
        $set = Snapshot::latest($tenant);

        if (! $set instanceof ResultSet) {
            return [[
                'metric' => 'No run',
                'value' => Render::NONE,
                'because' => 'no run has ever succeeded for this merchant, which is a different state from a run that had nothing to report',
            ]];
        }

        $lines = [];

        // Every definition the merchant currently asks for, first, so one the
        // run said nothing at all about is a sentence rather than an absence.
        // A source that answers successfully with no readings produces neither
        // a result nor a refusal — `ecommerce-reporting#6`, and the one place
        // the module still lets silence look like an answer.
        foreach (Snapshot::activeDefinitions($tenant) as $definition) {
            $lines[$definition->name] = [
                'metric' => $definition->name.' v'.$definition->version,
                'value' => Render::NONE,
                'because' => 'this run holds no number and no refusal for it: either the bound source answered with nothing over this window, which the domain records as neither, or the definition was not in the run',
            ];
        }

        // Newest bucket per definition. The set arrives ordered by name then
        // bucket, so the last one written for a name is the newest.
        foreach ($set->lines as $line) {
            $lines[$line->definitionName] = [
                'metric' => $line->definitionName.' v'.$line->definitionVersion,
                'value' => Render::measure($line->measure),
                'because' => 'obtained '.$line->bucketStartsAt->toDayDateTimeString(),
            ];
        }

        foreach ($set->refusals as $refusal) {
            $lines[$refusal->definitionName] = [
                'metric' => $refusal->definitionName.' v'.$refusal->definitionVersion,
                'value' => Render::NONE,
                'because' => Render::refusal($refusal->reason),
            ];
        }

        ksort($lines);

        return array_values($lines);
    }

    /** The window every figure above covers, named once rather than assumed per stat. */
    public function window(): string
    {
        $set = Snapshot::latest(PanelTenant::current());

        return $set instanceof ResultSet
            ? 'Run '.$set->runId.', covering '.Render::window($set->window)
            : 'Nothing has been computed, so there is no window to name.';
    }

    protected function getDescription(): ?string
    {
        return $this->window();
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        $stats = [];

        foreach ($this->lines() as $line) {
            $stats[] = Stat::make($line['metric'], $line['value'])
                ->description($line['because'])
                ->color($line['value'] === Render::NONE ? 'warning' : 'success');
        }

        return $stats;
    }
}

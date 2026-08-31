<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Liberu\Ecommerce\Reporting\Data\ResultSet;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Filament\Widgets\LatestRunOverview;
use Liberu\Ecommerce\Reporting\Filament\Widgets\OverdueDeliveries;
use UnitEnum;

/**
 * The page the host had and left empty.
 *
 * Its merchant panel registered a Reports navigation item whose page declared no
 * header widgets and rendered three lines of Blade, while all eight widgets were
 * mounted on the platform panel. This one carries the widgets and answers the
 * question the runbook has to: is nothing reported because nothing has run, or
 * because everything that ran was refused, or because there is genuinely nothing
 * to report? Those are three different faults and the host showed one screen.
 */
final class Readiness extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Readiness';

    protected static UnitEnum|string|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'Is anything actually being reported?';

    public static function canAccess(): bool
    {
        return PanelTenant::resolvable();
    }

    /** @return array<class-string> */
    public function getHeaderWidgets(): array
    {
        return [
            LatestRunOverview::class,
            OverdueDeliveries::class,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What this merchant has said a number is')
                ->description('The module owns the metadata of a report and none of its arithmetic. Without a definition there is no question to ask, and without a bound source there is nobody to ask it of.')
                ->schema([
                    Text::make(fn (): string => $this->definitionLine()),
                    UnorderedList::make(fn (): array => $this->sourceLines()),
                ]),

            Section::make('Whether it has ever run')
                ->description('The package schedules nothing. A host that never added the schedule line has a module that has never run, which is not the same as one with nothing to report.')
                ->schema([
                    Text::make(fn (): string => $this->runLine()),
                ]),

            Section::make('Where the answers go')
                ->description('Every attempt is a row, including the ones that refused, so a subscription that has gone quiet is countable rather than invisible.')
                ->schema([
                    Text::make(fn (): string => $this->deliveryLine()),
                ]),
        ]);
    }

    private function definitionLine(): string
    {
        $definitions = Snapshot::definitions(PanelTenant::current());

        if ($definitions === []) {
            return 'Nothing is defined. No number this merchant sees comes from here, and none of them says what it is — which is the host exactly: revenue computed three ways on one dashboard with nowhere to write down which one a figure was.';
        }

        return count($definitions).' definitions across every version, so a withdrawn one is still here beside the version that replaced it.';
    }

    /** @return list<string> */
    private function sourceLines(): array
    {
        $sources = Snapshot::sources(PanelTenant::current());

        if ($sources === []) {
            return ['No definition names a source, so nothing is asked of anybody.'];
        }

        $lines = [];

        foreach ($sources as $key => $bound) {
            $lines[] = $key.' — '.Render::seam(
                $bound,
                'something is bound and will be asked.',
                'nothing is bound, so every definition on this key is refused with a reason and gets no result row. Not a zero.',
            );
        }

        return $lines;
    }

    private function runLine(): string
    {
        $tenant = PanelTenant::current();
        $attempts = Snapshot::runCount($tenant);

        if ($attempts === 0) {
            return 'No run has ever been attempted. Add the schedule line from the adoption notes, or compute one by hand from the Runs screen.';
        }

        $set = Snapshot::latest($tenant);

        if (! $set instanceof ResultSet) {
            return $attempts.' runs have been attempted and none has succeeded. Each one is on the Runs screen with the state it ended in.';
        }

        if ($set->lines === [] && $set->refusals === []) {
            return 'The newest successful run asked for nothing and refused nothing, which is a run with nothing to report rather than a run that failed.';
        }

        return 'The newest successful run covered '.Render::window($set->window).'. It obtained '
            .count($set->lines).' numbers and refused '.count($set->refusals).' definitions'
            .($set->isComplete() ? '.' : ': '.implode('; ', Render::refusalLines($set->refusals)).'.')
            .$this->unaccountedLine($tenant, $set);
    }

    /**
     * Definitions the run holds neither a number nor a refusal for.
     *
     * A source that answers successfully with no readings produces neither —
     * `ecommerce-reporting#6` — so without this sentence those definitions are
     * absent from the page rather than unanswered on it.
     */
    private function unaccountedLine(string $tenant, ResultSet $set): string
    {
        $accounted = [];

        foreach ($set->lines as $line) {
            $accounted[$line->definitionName] = true;
        }

        foreach ($set->refusals as $refusal) {
            $accounted[$refusal->definitionName] = true;
        }

        $unaccounted = array_diff_key(Snapshot::activeDefinitions($tenant), $accounted);

        return $unaccounted === []
            ? ''
            : ' It holds neither a number nor a refusal for '.count($unaccounted)
                .' of the definitions this merchant currently asks for: '.implode(', ', array_keys($unaccounted))
                .'. Either the source answered with nothing over the window, which the module records as neither, or they were not in the run.';
    }

    private function deliveryLine(): string
    {
        $tenant = PanelTenant::current();
        $total = Snapshot::subscriptionCount($tenant);

        if ($total === 0) {
            return 'Nobody is subscribed. A run still computes and still records what it refused; there is simply nowhere to send it.';
        }

        $overdue = Snapshot::overdue($tenant);

        if ($overdue === []) {
            return $total.' subscriptions, every active one inside its cadence.';
        }

        $never = 0;

        foreach ($overdue as $status) {
            $never += $status->lastAttemptedAt === null ? 1 : 0;
        }

        return $total.' subscriptions, of which '.count($overdue).' are overdue and '.$never
            .' have never had a single attempt made against them.';
    }
}

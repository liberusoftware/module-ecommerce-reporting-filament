<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\Reporting\Data\SubscriptionStatus;
use Liberu\Ecommerce\Reporting\Enums\Cadence;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Models\ReportSubscription;

/**
 * Which of this merchant's subscriptions have gone quiet, and which never spoke.
 *
 * The domain decides what overdue means — cadence against the newest delivery
 * attempt, measured from one clock this request read once. This widget turns
 * that answer into a query that states the tenant, and nothing else.
 *
 * The host's equivalent read every merchant's rows: one method returned a
 * scoped total beside an unscoped breakdown, and the two did not add up.
 */
class OverdueDeliveries extends TableWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return PanelTenant::resolvable();
    }

    /**
     * The subscriptions the domain counts as overdue, by key.
     *
     * @return list<int>
     */
    public function overdueIds(): array
    {
        return array_map(
            static fn (SubscriptionStatus $status): int => $status->id,
            Snapshot::overdue(PanelTenant::current()),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Subscriptions that have not delivered')
            ->description('A subscription that has never delivered is a countable row rather than an absence. Paused ones are not here: nothing is expected of them.')
            ->query(fn (): Builder => ReportSubscription::query()
                ->where('tenant_id', PanelTenant::current())
                ->whereKey($this->overdueIds()))
            ->columns([
                TextColumn::make('name')->label('Subscription'),
                TextColumn::make('destination_ref')->label('Destination'),
                TextColumn::make('cadence')
                    ->label('Cadence')
                    ->formatStateUsing(fn (Cadence $state): string => Render::cadenceLabel($state)),
                TextColumn::make('delivery_standing')
                    ->label('Standing')
                    ->state(fn (ReportSubscription $record): string => SubscriptionResource::standing($record))
                    ->wrap(),
            ])
            ->defaultSort('name')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Every active subscription is inside its cadence')
            ->emptyStateDescription('Which is not the same as there being no subscriptions. A merchant with none has nothing to be late.');
    }
}

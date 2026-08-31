<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Reporting\Enums\DeliveryState;
use Liberu\Ecommerce\Reporting\Enums\RefusalReason;
use Liberu\Ecommerce\Reporting\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Models\ReportDelivery;

/**
 * Every attempt, including the ones that refused and the ones that failed.
 *
 * A retry is the next attempt rather than a rewrite of the last, so the row
 * that failed stays on this list. That is what makes "which subscriptions have
 * not delivered" a query.
 */
final class DeliveriesRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Delivery attempts';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('run_id')->label('Run')->sortable(),
                TextColumn::make('attempt')->label('Attempt')->sortable(),
                TextColumn::make('state')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryState $state): string => Render::deliveryLabel($state))
                    ->color(fn (DeliveryState $state): string => Render::deliveryColour($state)),
                TextColumn::make('reason')
                    ->label('Because')
                    ->state(fn (ReportDelivery $record): string => $record->reason instanceof RefusalReason
                        ? Render::refusal($record->reason)
                        : Render::NONE)
                    ->wrap(),
                TextColumn::make('response')
                    ->label('The destination said')
                    ->placeholder(Render::NONE)
                    ->wrap()
                    ->tooltip('What the bound destination returned, kept verbatim and truncated. This module does not interpret it.'),
                TextColumn::make('attempted_at')->label('Attempted')->dateTime()->sortable(),
            ])
            ->filters([])
            ->defaultSort('attempted_at', 'desc')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has ever been sent to this destination')
            ->emptyStateDescription('Not one failed attempt and not one refused one. Either no run has been delivered since this subscription was created, or the subscription was paused throughout.');
    }
}

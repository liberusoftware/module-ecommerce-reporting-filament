<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Models\ReportResult;

/**
 * The numbers this run obtained.
 *
 * Only the ones it obtained: a definition it refused is not a row here with a
 * nought in it, it is a refusal on the run above. The empty state says so,
 * because an empty table and a refused definition look identical otherwise —
 * which is the whole fault the module exists to fix.
 */
final class ResultsRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'results';

    protected static ?string $title = 'Numbers obtained';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('definition_name')->label('Metric')->searchable()->sortable(),
                TextColumn::make('definition_version')
                    ->label('Version')
                    ->tooltip('Stored on the result as a column. Editing the definition afterwards cannot change what this number meant.'),
                TextColumn::make('bucket_starts_at')->label('Bucket')->dateTime()->sortable(),
                TextColumn::make('value_numerator')
                    ->label('Value')
                    ->state(fn (ReportResult $record): string => Render::measure($record->measure()))
                    ->tooltip('Money is minor units and a currency; a ratio is two integers. Neither is a float and neither is a percentage.'),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->badge()
                    ->formatStateUsing(fn (Unit $state): string => Render::unitLabel($state)),
            ])
            ->filters([])
            ->defaultSort('definition_name')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('This run wrote no numbers')
            ->emptyStateDescription('Which is not a run of noughts. Either it asked for nothing, or every definition it asked for was refused — and the refusals, with their reasons, are on the run itself.');
    }
}

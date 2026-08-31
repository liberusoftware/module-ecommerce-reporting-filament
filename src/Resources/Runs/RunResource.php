<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Runs;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Reporting\Enums\RunState;
use Liberu\Ecommerce\Reporting\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\Pages\ListRuns;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\Pages\ViewRun;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RelationManagers\ResultsRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Models\ReportRun;
use Liberu\Ecommerce\Reporting\Policies\CustodyPolicy;
use UnitEnum;

/**
 * Every attempt to compute this merchant's definitions, and what came of each.
 *
 * A run that could not obtain a definition wrote no result row for it and
 * recorded a refusal with a reason. Both halves are on this screen, because a
 * dashboard that shows only the numbers cannot tell "nobody bought anything"
 * from "nothing computed this".
 */
final class RunResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = ReportRun::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'run';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected static ?string $navigationLabel = 'Runs';

    protected static UnitEnum|string|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 30;

    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    /** Standing is the domain's answer, asked by run id rather than re-derived from the row. */
    public static function canView(Model $record): bool
    {
        return $record instanceof ReportRun
            && PanelTenant::resolvable()
            && CustodyPolicy::ownsRun(PanelTenant::current(), $record->id);
    }

    /** @return Builder<ReportRun> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<ReportRun> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('results');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [ResultsRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('state')
                    ->label('Outcome')
                    ->badge()
                    ->formatStateUsing(fn (RunState $state): string => Render::runLabel($state))
                    ->color(fn (RunState $state): string => Render::runColour($state))
                    ->sortable(),
                TextColumn::make('window_from')
                    ->label('Window')
                    ->state(fn (ReportRun $record): string => Render::window($record->toWindow()))
                    ->wrap()
                    ->tooltip('Stamped once on the run. Every number in it cites this window, so a page rendered across midnight cannot report two.'),
                TextColumn::make('requested')->label('Asked'),
                TextColumn::make('results_count')
                    ->label('Numbers')
                    ->tooltip('Result rows this run wrote. A definition it could not obtain has none.'),
                TextColumn::make('refused')
                    ->label('Refused')
                    ->color(fn (ReportRun $record): string => $record->refused > 0 ? 'warning' : 'gray')
                    ->tooltip('Definitions this run could not obtain. Each one has a reason and no number — never a zero.'),
                TextColumn::make('refusals')
                    ->label('Because')
                    ->state(fn (ReportRun $record): array => Render::refusalLines($record->refusalList()))
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder(Render::NONE)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('finished_at')->label('Finished')->dateTime()->placeholder(Render::NONE),
            ])
            ->filters([
                SelectFilter::make('state')->label('Outcome')->options(fn (): array => self::stateOptions()),
                SelectFilter::make('grain')->label('Grain')->options(fn (): array => DefinitionResource::grainOptions()),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-play-circle')
            ->emptyStateHeading('Nothing has ever been computed for this merchant')
            ->emptyStateDescription('The package schedules nothing. A host that never added the schedule line has a reporting module that has never run, which is a different state from one with nothing to report — and this screen is where they differ.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The run')
                ->columns(3)
                ->schema([
                    TextEntry::make('state')
                        ->label('Outcome')
                        ->badge()
                        ->state(fn (ReportRun $record): string => Render::runLabel($record->state))
                        ->color(fn (ReportRun $record): string => Render::runColour($record->state))
                        ->helperText('A run that failed partway wrote no results at all: the result writes are one transaction.'),
                    TextEntry::make('started_at')->label('Started')->dateTime(),
                    TextEntry::make('finished_at')->label('Finished')->dateTime()->placeholder(Render::NONE),
                    TextEntry::make('window_from')
                        ->label('Window')
                        ->state(fn (ReportRun $record): string => Render::window($record->toWindow()))
                        ->columnSpanFull()
                        ->helperText('One window, stamped on the run and cited by every number in it. The host let each of eight widgets compute its own and named none of them.'),
                ]),

            Section::make('What it obtained')
                ->description('Asked, computed and refused side by side, because a run that computed nothing and refused nothing is a run with nothing to report, and a run that refused everything is a run that could not ask.')
                ->columns(3)
                ->schema([
                    TextEntry::make('requested')->label('Definitions asked'),
                    TextEntry::make('computed')->label('Numbers written'),
                    TextEntry::make('refused')->label('Definitions refused'),
                ]),

            Section::make('What it could not obtain, and why')
                ->description('Each of these has no result row for this run. None of them is a zero.')
                ->schema([
                    TextEntry::make('refusals')
                        ->hiddenLabel()
                        ->state(fn (ReportRun $record): array => Render::refusalLines($record->refusalList()))
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('Nothing was refused: every definition this run asked for came back.')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        $options = [];

        foreach (RunState::cases() as $state) {
            $options[$state->value] = Render::runLabel($state);
        }

        return $options;
    }
}

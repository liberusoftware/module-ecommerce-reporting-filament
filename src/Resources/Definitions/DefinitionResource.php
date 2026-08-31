<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Definitions;

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
use Liberu\Ecommerce\Reporting\Enums\DefinitionState;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages\ListDefinitions;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages\ViewDefinition;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Models\MetricDefinition;
use Liberu\Ecommerce\Reporting\Support\Seams;
use UnitEnum;

/**
 * What each number on this merchant's reports is, and who answers for it.
 *
 * The host computed revenue three different ways on one dashboard and had
 * nowhere to write down which one a figure was. This is that place: a name, a
 * version, the source that owns the arithmetic, the window and grain it is
 * asked over, and the unit it comes back in.
 */
final class DefinitionResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = MetricDefinition::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'definition';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-variable';

    protected static ?string $navigationLabel = 'Definitions';

    protected static UnitEnum|string|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof MetricDefinition
            && PanelTenant::resolvable()
            && $record->tenant_id === PanelTenant::current();
    }

    /** @return Builder<MetricDefinition> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<MetricDefinition> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Metric')->searchable()->sortable(),
                TextColumn::make('version')
                    ->label('Version')
                    ->sortable()
                    ->tooltip('A result cites this number, not the row. Redefining the metric mints the next one and leaves this one addressable.'),
                TextColumn::make('source_key')
                    ->label('Answered by')
                    ->badge()
                    ->color(fn (MetricDefinition $record): string => Seams::source($record->source_key) === null ? 'danger' : 'success')
                    ->tooltip('Whoever owns the data owns the arithmetic. Red means nothing is bound to that key, so every run refuses this definition.')
                    ->searchable(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->badge()
                    ->formatStateUsing(fn (Unit $state): string => Render::unitLabel($state)),
                TextColumn::make('currency')->label('Currency')->placeholder(Render::NONE),
                TextColumn::make('grain')
                    ->label('Grain')
                    ->formatStateUsing(fn (Grain $state): string => Render::grainLabel($state)),
                TextColumn::make('window_days')->label('Window')->suffix(' days'),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (DefinitionState $state): string => Render::definitionLabel($state))
                    ->color(fn (DefinitionState $state): string => Render::definitionColour($state))
                    ->sortable(),
                TextColumn::make('withdrawn_at')->label('Withdrawn')->dateTime()->placeholder(Render::NONE),
            ])
            ->filters([
                SelectFilter::make('state')->label('State')->options(fn (): array => self::stateOptions()),
                SelectFilter::make('unit')->label('Unit')->options(fn (): array => self::unitOptions()),
                SelectFilter::make('grain')->label('Grain')->options(fn (): array => self::grainOptions()),
            ])
            ->defaultSort('name')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-variable')
            ->emptyStateHeading('This merchant has defined no metric')
            ->emptyStateDescription('Which is the host: a dashboard showing a revenue figure, a revenue-per-product table and a lifetime-value segment, no two of them computed the same way, and nothing anywhere saying so.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The question')
                ->description('A definition is immutable once written. Editing one would change what a number already shown to a merchant meant.')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->label('Metric'),
                    TextEntry::make('version')->label('Version'),
                    TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->state(fn (MetricDefinition $record): string => Render::definitionLabel($record->state))
                        ->color(fn (MetricDefinition $record): string => Render::definitionColour($record->state)),
                    TextEntry::make('source_key')
                        ->label('Answered by')
                        ->helperText(fn (MetricDefinition $record): string => self::binding($record)),
                    TextEntry::make('grain')
                        ->label('Grain')
                        ->state(fn (MetricDefinition $record): string => Render::grainLabel($record->grain)),
                    TextEntry::make('window_days')->label('Window in days'),
                ]),

            Section::make('What it comes back in')
                ->description('A source answering in another shape refuses the whole definition rather than being coerced into this one.')
                ->columns(2)
                ->schema([
                    TextEntry::make('unit')
                        ->label('Unit')
                        ->state(fn (MetricDefinition $record): string => Render::unitLabel($record->unit)),
                    TextEntry::make('currency')
                        ->label('Currency')
                        ->placeholder(Render::NONE)
                        ->helperText('Money carries an ISO 4217 code and nothing else carries one. Values are held in minor units.'),
                ]),

            Section::make('When')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label('Defined')->dateTime(),
                    TextEntry::make('withdrawn_at')
                        ->label('Withdrawn')
                        ->dateTime()
                        ->placeholder(Render::NONE)
                        ->helperText('Withdrawal stops new results. The old ones stay readable, because they cite the version rather than the row.'),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListDefinitions::route('/'),
            'view' => ViewDefinition::route('/{record}'),
        ];
    }

    /** Whether anything answers this definition's source key, said on the record rather than inferred from an empty run. */
    public static function binding(MetricDefinition $record): string
    {
        return Render::seam(
            Snapshot::sources($record->tenant_id)[$record->source_key] ?? false,
            'Something is bound to this key and will be asked.',
            'Nothing is bound to this key, so every run refuses this definition and writes no result for it. That is a refusal with a reason, not a zero.',
        );
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        $options = [];

        foreach (DefinitionState::cases() as $state) {
            $options[$state->value] = Render::definitionLabel($state);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function unitOptions(): array
    {
        $options = [];

        foreach (Unit::cases() as $unit) {
            $options[$unit->value] = Render::unitLabel($unit);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function grainOptions(): array
    {
        $options = [];

        foreach (Grain::cases() as $grain) {
            $options[$grain->value] = Render::grainLabel($grain);
        }

        return $options;
    }
}

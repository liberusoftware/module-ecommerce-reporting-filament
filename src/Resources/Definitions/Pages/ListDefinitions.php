<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Reporting\Actions\DefineMetric;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\Apply;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;

/**
 * Defining a metric is a domain action, not a create form. Naming one that
 * already exists mints the next version rather than editing the old one.
 */
final class ListDefinitions extends ListRecords
{
    protected static string $resource = DefinitionResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('define')
                ->label('Define a metric')
                ->icon('heroicon-o-plus')
                ->modalHeading('Say what a number is')
                ->modalDescription('A definition names the question and the module that answers it. This module computes nothing: if nothing is bound to the source key, every run refuses the definition by name and writes no result for it.')
                ->modalSubmitActionLabel('Define it')
                ->schema([
                    TextInput::make('name')
                        ->label('Metric name')
                        ->required()
                        ->maxLength(64)
                        ->helperText('Naming one that already exists mints the next version. The old one stays addressable, because every result cites the version that produced it.'),
                    TextInput::make('source_key')
                        ->label('Source key')
                        ->required()
                        ->maxLength(64)
                        ->helperText('Whoever owns the data owns the arithmetic. This is an opaque key the host binds to an implementation; this module never resolves it to a table.'),
                    Select::make('unit')
                        ->label('Unit')
                        ->required()
                        ->live()
                        ->default(Unit::Count->value)
                        ->options(fn (): array => DefinitionResource::unitOptions())
                        ->helperText('A source answering in another shape refuses the whole definition rather than being coerced.'),
                    TextInput::make('currency')
                        ->label('Currency')
                        ->maxLength(3)
                        ->minLength(3)
                        ->required(fn (Get $get): bool => $get('unit') === Unit::Money->value)
                        ->visible(fn (Get $get): bool => $get('unit') === Unit::Money->value)
                        ->helperText('An ISO 4217 code. Money is held in minor units; only money carries a currency.'),
                    Select::make('grain')
                        ->label('Grain')
                        ->required()
                        ->default(Grain::Daily->value)
                        ->options(fn (): array => DefinitionResource::grainOptions())
                        ->helperText('All four are askable because the module emits no SQL: the source is handed a window and a grain and returns buckets.'),
                    TextInput::make('window_days')
                        ->label('Window in days')
                        ->required()
                        ->numeric()
                        ->default('30')
                        ->minValue(1)
                        ->maxValue(3650),
                ])
                ->action(function (array $data): void {
                    /** @var array{name: string, source_key: string, unit: string, currency?: ?string, grain: string, window_days: string} $data */
                    $unit = Unit::from($data['unit']);
                    $currency = $unit->needsCurrency() ? strtoupper((string) ($data['currency'] ?? '')) : null;

                    $definition = App::make(DefineMetric::class)(
                        PanelTenant::current(),
                        $data['name'],
                        $data['source_key'],
                        $unit,
                        Grain::from($data['grain']),
                        (int) $data['window_days'],
                        $currency,
                    );

                    Snapshot::forget();

                    Apply::say(
                        'Defined',
                        $definition->name.' is now at version '.$definition->version
                            .', answered by '.$definition->source_key.'. '
                            .DefinitionResource::binding($definition),
                        'success',
                    );
                }),
        ];
    }
}

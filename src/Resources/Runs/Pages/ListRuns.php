<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Runs\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Reporting\Actions\ComputeReport;
use Liberu\Ecommerce\Reporting\Data\RunReport;
use Liberu\Ecommerce\Reporting\Data\Window;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RunResource;
use Liberu\Ecommerce\Reporting\Filament\Support\Apply;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;

/**
 * Running the report by hand, which is the only way it runs until the host adds
 * the schedule line. The package schedules nothing on purpose.
 */
final class ListRuns extends ListRecords
{
    protected static string $resource = RunResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('compute')
                ->label('Compute now')
                ->icon('heroicon-o-play')
                ->modalHeading('Compute this merchant\'s active definitions')
                ->modalDescription('One window is stamped on the run and every number cites it. A definition whose source is not bound, raises, or answers in the wrong unit is refused by name and gets no result row — this module never writes a zero it did not obtain.')
                ->modalSubmitActionLabel('Run it')
                ->schema([
                    TextInput::make('days')
                        ->label('Window in days')
                        ->required()
                        ->numeric()
                        ->default('30')
                        ->minValue(1)
                        ->maxValue(3650),
                    Select::make('grain')
                        ->label('Grain')
                        ->required()
                        ->default(Grain::Daily->value)
                        ->options(fn (): array => DefinitionResource::grainOptions())
                        ->helperText('The grain the sources are asked for. The module emits no SQL, so all four are askable and a source that cannot answer over one refuses rather than substituting another.'),
                ])
                ->action(function (array $data): void {
                    /** @var array{days: string, grain: string} $data */
                    // One clock for the whole request, shared with every widget
                    // on the page. The host's eight widgets each called now().
                    $window = Window::endingAt(Snapshot::asOf(), (int) $data['days'], Grain::from($data['grain']));

                    $report = App::make(ComputeReport::class)(PanelTenant::current(), $window, null);

                    Snapshot::forget();

                    Apply::say(
                        $report->refused() > 0 ? 'It ran, and refused some of it' : 'It ran',
                        self::sentence($report),
                        $report->computed === 0 && $report->refused() > 0 ? 'danger' : 'success',
                    );
                }),
        ];
    }

    /** What the run obtained and what it did not, never a bare "done". */
    private static function sentence(RunReport $report): string
    {
        $said = 'It asked '.$report->requested.' definitions over '.Render::window($report->window)
            .', and wrote '.$report->computed.' numbers.';

        if ($report->refusals === []) {
            return $said.' Nothing was refused.';
        }

        return $said.' It refused '.$report->refused().': '
            .implode('; ', Render::refusalLines($report->refusals)).'.';
    }
}

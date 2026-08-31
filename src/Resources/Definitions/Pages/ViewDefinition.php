<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Reporting\Actions\WithdrawMetric;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\Apply;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Models\MetricDefinition;

/** One definition. There is no edit: withdrawal is the only change a definition accepts. */
final class ViewDefinition extends ViewRecord
{
    protected static string $resource = DefinitionResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('withdraw')
                ->label('Withdraw')
                ->icon('heroicon-o-archive-box')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Stop this definition producing new results')
                ->modalDescription('Withdrawal is not a delete. Every number this version already produced stays readable and still cites it; runs stop asking for it and record DefinitionWithdrawn if it is named.')
                ->modalSubmitActionLabel('Withdraw it')
                // Not hidden on a withdrawn definition. Whether a definition may
                // be withdrawn is the domain's rule, and a control the panel hid
                // is not a control: the action re-reads the row scoped to this
                // tenant and refuses rather than withdrawing it twice.
                ->action(function (MetricDefinition $record): void {
                    $withdrawn = App::make(WithdrawMetric::class)(
                        PanelTenant::current(),
                        $record->name,
                        $record->version,
                    );

                    Snapshot::forget();

                    Apply::say(
                        $withdrawn ? 'Withdrawn' : 'Nothing happened',
                        $withdrawn
                            ? $record->name.' v'.$record->version.' produces no further results. The ones it produced still cite it.'
                            : 'No active definition of that name and version for this merchant.',
                        $withdrawn ? 'success' : 'danger',
                    );
                }),
        ];
    }
}

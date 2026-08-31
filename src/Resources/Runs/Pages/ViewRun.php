<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Runs\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Reporting\Actions\DeliverRun;
use Liberu\Ecommerce\Reporting\Data\DeliveryReport;
use Liberu\Ecommerce\Reporting\Enums\RefusalReason;
use Liberu\Ecommerce\Reporting\Enums\RunState;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RunResource;
use Liberu\Ecommerce\Reporting\Filament\Support\Apply;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Models\ReportRun;

/** One run, its numbers and its refusals. There is nothing to do to a run: it happened. */
final class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('deliver')
                ->label('Deliver this run')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->modalHeading('Send this run to every active subscription')
                ->modalDescription('Each subscription gets the slice of this run it asked for, refusals included. Every attempt is a row, including the ones that refused, so which subscriptions have not delivered stays a query rather than a log grep.')
                ->modalSubmitActionLabel('Deliver it')
                ->visible(fn (ReportRun $record): bool => $record->state === RunState::Succeeded)
                ->action(function (ReportRun $record): void {
                    // The tenant and the run id, never a loaded run: the domain
                    // publishes one method that takes a model and does not
                    // re-check custody, and this is not it.
                    $reports = App::make(DeliverRun::class)(PanelTenant::current(), $record->id);

                    Snapshot::forget();

                    Apply::say(
                        self::title($reports),
                        self::sentence($reports),
                        self::colour($reports),
                    );
                }),
        ];
    }

    /** @param  list<DeliveryReport>  $reports */
    private static function title(array $reports): string
    {
        return $reports === [] ? 'Nothing to deliver' : 'Delivered';
    }

    /** @param  list<DeliveryReport>  $reports */
    private static function colour(array $reports): string
    {
        foreach ($reports as $report) {
            if ($report->reason instanceof RefusalReason) {
                return 'warning';
            }
        }

        return $reports === [] ? 'warning' : 'success';
    }

    /** @param  list<DeliveryReport>  $reports */
    private static function sentence(array $reports): string
    {
        if ($reports === []) {
            return 'This merchant has no active subscription, so nothing was sent. That is an empty attempt list, not a failed delivery.';
        }

        $lines = [];

        foreach ($reports as $report) {
            $lines[] = 'subscription '.$report->subscriptionId.' attempt '.$report->attempt.': '
                .strtolower(Render::deliveryLabel($report->state))
                .($report->reason instanceof RefusalReason ? ', because '.Render::refusal($report->reason) : '');
        }

        return implode('; ', $lines).'.';
    }
}

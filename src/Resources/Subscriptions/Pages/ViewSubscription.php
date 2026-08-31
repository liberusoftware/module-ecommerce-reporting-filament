<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\Reporting\Actions\SetSubscriptionState;
use Liberu\Ecommerce\Reporting\Enums\SubscriptionState;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\Apply;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Models\ReportSubscription;

/**
 * Pausing and resuming, which is the whole of what an operator may do to a
 * subscription. The delivery history is append-only and the subject reference
 * is redacted by the erasure path rather than edited here.
 */
final class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            self::move('pause', 'Pause', 'heroicon-o-pause', 'warning', SubscriptionState::Paused, SubscriptionState::Active),
            self::move('resume', 'Resume', 'heroicon-o-play', 'success', SubscriptionState::Active, SubscriptionState::Paused),
        ];
    }

    private static function move(
        string $name,
        string $label,
        string $icon,
        string $colour,
        SubscriptionState $to,
        SubscriptionState $from,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color($colour)
            ->requiresConfirmation()
            ->modalDescription($to === SubscriptionState::Paused
                ? 'A paused subscription is skipped by delivery and stops being counted as overdue. Its history stays.'
                : 'An active subscription receives the slice of every delivered run it asked for, refusals included.')
            ->visible(fn (ReportSubscription $record): bool => $record->state === $from)
            ->action(function (ReportSubscription $record) use ($to): void {
                // The action re-reads the subscription scoped to the tenant it
                // is handed and answers false only when that merchant has none
                // of that name — which this page cannot reach, because the
                // record came out of the same tenant-scoped query.
                App::make(SetSubscriptionState::class)(PanelTenant::current(), $record->name, $to);

                Snapshot::forget();

                Apply::say('Moved', $record->name.' is now '.strtolower(Render::subscriptionLabel($to)).'.', 'success');
            });
    }
}

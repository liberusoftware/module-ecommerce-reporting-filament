<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions;

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
use Liberu\Ecommerce\Reporting\Data\SubscriptionStatus;
use Liberu\Ecommerce\Reporting\Enums\Cadence;
use Liberu\Ecommerce\Reporting\Enums\DeliveryState;
use Liberu\Ecommerce\Reporting\Enums\SubscriptionState;
use Liberu\Ecommerce\Reporting\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Models\ReportSubscription;
use UnitEnum;

/**
 * Who wants which numbers delivered where, and whether they have been.
 *
 * A subscription that has never delivered is a countable row rather than an
 * absence, so this screen answers "which of these has gone quiet" without a log
 * grep. The subject reference is not on the listing: picking a subscription out
 * of a list needs its name and its destination, and nothing about picking one
 * needs to say whose it is.
 */
final class SubscriptionResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = ReportSubscription::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $modelLabel = 'subscription';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static ?string $navigationLabel = 'Subscriptions';

    protected static UnitEnum|string|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool
    {
        return PanelTenant::resolvable();
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof ReportSubscription
            && PanelTenant::resolvable()
            && $record->tenant_id === PanelTenant::current();
    }

    /** @return Builder<ReportSubscription> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<ReportSubscription> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('deliveries');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Subscription')->searchable()->sortable(),
                TextColumn::make('destination_ref')
                    ->label('Destination')
                    ->searchable()
                    ->tooltip('An opaque reference the bound destination resolves. This module never resolves it.'),
                TextColumn::make('definitions')
                    ->label('Wants')
                    ->state(fn (ReportSubscription $record): array => $record->definitionNames())
                    ->listWithLineBreaks()
                    ->bulleted()
                    ->placeholder('Nothing — this subscription names no definition, so every delivery is empty'),
                TextColumn::make('cadence')
                    ->label('Cadence')
                    ->formatStateUsing(fn (Cadence $state): string => Render::cadenceLabel($state)),
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionState $state): string => Render::subscriptionLabel($state))
                    ->color(fn (SubscriptionState $state): string => Render::subscriptionColour($state))
                    ->sortable(),
                TextColumn::make('deliveries_count')
                    ->label('Attempts')
                    ->tooltip('Every attempt is a row, including the ones that refused.'),
                TextColumn::make('delivery_standing')
                    ->label('Delivery')
                    ->badge()
                    ->state(fn (ReportSubscription $record): string => self::standing($record))
                    ->color(fn (ReportSubscription $record): string => self::overdue($record) ? 'warning' : 'success')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('state')->label('State')->options(fn (): array => self::stateOptions()),
                SelectFilter::make('cadence')->label('Cadence')->options(fn (): array => self::cadenceOptions()),
            ])
            ->defaultSort('name')
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-inbox-arrow-down')
            ->emptyStateHeading('Nobody is subscribed to this merchant\'s reports')
            ->emptyStateDescription('A run still computes and still records what it refused; it just has nowhere to send the answer. Subscriptions are created by the host, because a subject reference names a person and a panel cannot mint one.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The subscription')
                ->columns(3)
                ->schema([
                    TextEntry::make('name')->label('Name'),
                    TextEntry::make('state')
                        ->label('State')
                        ->badge()
                        ->state(fn (ReportSubscription $record): string => Render::subscriptionLabel($record->state))
                        ->color(fn (ReportSubscription $record): string => Render::subscriptionColour($record->state)),
                    TextEntry::make('cadence')
                        ->label('Cadence')
                        ->state(fn (ReportSubscription $record): string => Render::cadenceLabel($record->cadence))
                        ->helperText('How long it may go undelivered before it is overdue.'),
                    TextEntry::make('destination_ref')->label('Destination'),
                    TextEntry::make('subject_ref')
                        ->label('Subject')
                        ->state(fn (ReportSubscription $record): string => Render::subject($record->subject_ref))
                        ->helperText('The only personal datum this module holds. Erasure redacts it and keeps the aggregates, because a result is a fact about a merchant rather than about a person.')
                        ->columnSpan(2),
                ]),

            Section::make('What it asks for')
                ->schema([
                    TextEntry::make('definitions')
                        ->hiddenLabel()
                        ->state(fn (ReportSubscription $record): array => $record->definitionNames())
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->placeholder('Nothing. A subscription naming no definition receives an empty slice of every run.')
                        ->columnSpanFull(),
                ]),

            Section::make('Whether it has been delivered')
                ->schema([
                    TextEntry::make('delivery_standing')
                        ->hiddenLabel()
                        ->state(fn (ReportSubscription $record): string => self::standing($record))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'view' => ViewSubscription::route('/{record}'),
        ];
    }

    /** Whether the domain counts this subscription as overdue, asked once per request for the whole tenant. */
    public static function overdue(ReportSubscription $record): bool
    {
        foreach (Snapshot::overdue($record->tenant_id) as $status) {
            if ($status->id === $record->id) {
                return true;
            }
        }

        return false;
    }

    /** Delivered when it should have been, or the fact that it has not — never a blank cell. */
    public static function standing(ReportSubscription $record): string
    {
        $status = self::status($record);

        if ($status === null) {
            return $record->state === SubscriptionState::Active
                ? 'Delivered inside its cadence.'
                : 'Paused, so nothing is expected of it and nothing counts it as late.';
        }

        $when = $status->lastAttemptedAt === null ? Render::NONE : $status->lastAttemptedAt->toDayDateTimeString();

        return match ($status->lastAttemptState) {
            null => 'Never attempted. Nothing has ever been sent to this destination, which is a row you can count rather than a silence.',
            DeliveryState::Delivered => 'Overdue. Last delivered '.$when.', longer ago than its cadence allows.',
            DeliveryState::Failed, DeliveryState::Refused => 'Attempted '.$when.' and did not deliver: '
                .strtolower(Render::deliveryLabel($status->lastAttemptState)).'. Nothing has reached the destination.',
        };
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        $options = [];

        foreach (SubscriptionState::cases() as $state) {
            $options[$state->value] = Render::subscriptionLabel($state);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function cadenceOptions(): array
    {
        $options = [];

        foreach (Cadence::cases() as $cadence) {
            $options[$cadence->value] = Render::cadenceLabel($cadence);
        }

        return $options;
    }

    private static function status(ReportSubscription $record): ?SubscriptionStatus
    {
        foreach (Snapshot::overdue($record->tenant_id) as $status) {
            if ($status->id === $record->id) {
                return $status;
            }
        }

        return null;
    }
}

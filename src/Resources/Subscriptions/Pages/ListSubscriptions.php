<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;

/**
 * There is no "subscribe" here. A subscription carries a subject reference
 * naming a person, and a panel that minted one would be inventing an identity
 * this module is not the custodian of; the host creates them.
 */
final class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;
}

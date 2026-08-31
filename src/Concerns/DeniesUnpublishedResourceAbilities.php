<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every ability the domain does not publish, forced closed by name. A missing
 * policy method is permissive — Filament falls through to `allow()` — so an
 * ability nobody thought about is open unless it is answered.
 *
 * `canDelete` is the point of this package. A result and a delivery both raise
 * from a `deleting` hook, and a definition raises from both `updating` and
 * `deleting`; a delete offered here would fatal rather than refuse, and an
 * ability that fatals is still an ability that was offered. A definition is
 * retired by being withdrawn, which leaves every number that cites it readable.
 *
 * `canEdit` is closed because a number computed in March under one definition
 * of revenue must not change meaning when the definition is edited in April.
 * Redefining a metric mints the next version, which is a domain action and not
 * a form.
 *
 * `canViewAny` and `canView` are not in this list. They are stated on each
 * resource and answered there.
 */
trait DeniesUnpublishedResourceAbilities
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}

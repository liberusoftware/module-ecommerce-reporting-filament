<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\Reporting\Filament\Support;

use Liberu\Ecommerce\Reporting\Data\Measure;
use Liberu\Ecommerce\Reporting\Data\Refusal;
use Liberu\Ecommerce\Reporting\Data\Window;
use Liberu\Ecommerce\Reporting\Enums\Cadence;
use Liberu\Ecommerce\Reporting\Enums\DefinitionState;
use Liberu\Ecommerce\Reporting\Enums\DeliveryState;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Enums\RefusalReason;
use Liberu\Ecommerce\Reporting\Enums\RunState;
use Liberu\Ecommerce\Reporting\Enums\SubscriptionState;
use Liberu\Ecommerce\Reporting\Enums\Unit;

/**
 * The sentences this panel is most likely to get wrong, written once.
 *
 * Nothing here invents a figure. A number the module could not obtain has no
 * result row, and the whole point of the module is that a zero would read as an
 * answer: the host showed "0% increase" with an up arrow for a merchant's first
 * month and for a flat month alike. Every absence renders as the refusal that
 * caused it.
 *
 * Every `match` is over a domain enum with no default arm, so a new case is a
 * hole here rather than a blank on a screen, and `RenderTest` walks `cases()`.
 */
final class Render
{
    public const NONE = '—';

    /** Why a number is missing, in a sentence an operator can act on. */
    public static function refusal(RefusalReason $reason): string
    {
        return match ($reason) {
            RefusalReason::NoSourceBound => 'nothing is bound to answer this definition\'s source key, so nobody was asked — which is not the same as an answer of nothing',
            RefusalReason::SourceUnavailable => 'the bound source was asked and raised, so the number is unknown rather than zero',
            RefusalReason::UnitMismatch => 'the source answered in a shape that is not the unit this definition declares, and the whole series was refused rather than coerced',
            RefusalReason::WindowNotSupported => 'the source cannot answer over this window or grain, and said so rather than answering over a different one',
            RefusalReason::DefinitionWithdrawn => 'this definition has been withdrawn, so it produces no new results; the ones it already produced still cite it',
            RefusalReason::NoDestinationBound => 'nothing is bound to receive a result set, so the attempt was recorded as refused rather than quietly not made',
        };
    }

    /** One refused definition, named with its version, because a result cites a version and not a name. */
    public static function refusalLine(Refusal $refusal): string
    {
        return $refusal->definitionName.' v'.$refusal->definitionVersion.' — '.self::refusal($refusal->reason);
    }

    /**
     * @param  list<Refusal>  $refusals
     * @return list<string>
     */
    public static function refusalLines(array $refusals): array
    {
        return array_map(self::refusalLine(...), $refusals);
    }

    public static function runLabel(RunState $state): string
    {
        return match ($state) {
            RunState::Running => 'Running',
            RunState::Succeeded => 'Succeeded',
            RunState::Failed => 'Failed',
        };
    }

    public static function runColour(RunState $state): string
    {
        return match ($state) {
            RunState::Running => 'warning',
            RunState::Succeeded => 'success',
            RunState::Failed => 'danger',
        };
    }

    public static function deliveryLabel(DeliveryState $state): string
    {
        return match ($state) {
            DeliveryState::Delivered => 'Delivered',
            DeliveryState::Failed => 'Failed',
            DeliveryState::Refused => 'Refused',
        };
    }

    /** Delivered, failed and refused must not share a colour: two of them are the host's silence. */
    public static function deliveryColour(DeliveryState $state): string
    {
        return match ($state) {
            DeliveryState::Delivered => 'success',
            DeliveryState::Failed => 'danger',
            DeliveryState::Refused => 'warning',
        };
    }

    public static function definitionLabel(DefinitionState $state): string
    {
        return match ($state) {
            DefinitionState::Active => 'Active',
            // Not deleted. Every number it ever produced still cites it.
            DefinitionState::Withdrawn => 'Withdrawn',
        };
    }

    public static function definitionColour(DefinitionState $state): string
    {
        return match ($state) {
            DefinitionState::Active => 'success',
            DefinitionState::Withdrawn => 'gray',
        };
    }

    public static function subscriptionLabel(SubscriptionState $state): string
    {
        return match ($state) {
            SubscriptionState::Active => 'Active',
            SubscriptionState::Paused => 'Paused',
        };
    }

    public static function subscriptionColour(SubscriptionState $state): string
    {
        return match ($state) {
            SubscriptionState::Active => 'success',
            SubscriptionState::Paused => 'gray',
        };
    }

    public static function grainLabel(Grain $grain): string
    {
        return match ($grain) {
            Grain::Hourly => 'Hourly',
            Grain::Daily => 'Daily',
            Grain::Weekly => 'Weekly',
            Grain::Monthly => 'Monthly',
        };
    }

    public static function cadenceLabel(Cadence $cadence): string
    {
        return match ($cadence) {
            Cadence::Daily => 'Daily',
            Cadence::Weekly => 'Weekly',
            Cadence::Monthly => 'Monthly',
        };
    }

    public static function unitLabel(Unit $unit): string
    {
        return match ($unit) {
            Unit::Money => 'Money',
            Unit::Count => 'Count',
            Unit::Ratio => 'Ratio',
            Unit::Duration => 'Duration',
        };
    }

    /**
     * A number and what it is, with no arithmetic done to it.
     *
     * Money is the integer minor amount and its code, marked as minor units.
     * The domain stores no exponent, so dividing by a hundred would be this
     * package deciding what a currency's exponent is — right for GBP, wrong for
     * JPY and for KWD. The host's own orders widget hard-coded `->money('USD')`
     * and rendered every order in dollars whatever it was taken in; a column
     * that turns a minor-unit integer into a major-unit decimal is the same
     * mistake made with more confidence. Filed as `ecommerce-reporting#1`, and
     * the `-livewire` surface renders it identically.
     *
     * A ratio is the two integers it was stored as. Not a percentage — the
     * host's conversion rate was a decimal(5,2) percent that clamped at 999.99 —
     * and not a decimal, because the quotient is a float and a float is what
     * this module refuses.
     */
    public static function measure(Measure $measure): string
    {
        return match ($measure->unit) {
            Unit::Money => $measure->numerator.' '.((string) $measure->currency).' (minor units)',
            Unit::Count => (string) $measure->numerator,
            Unit::Ratio => $measure->numerator.' / '.$measure->denominator,
            Unit::Duration => $measure->numerator.' seconds',
        };
    }

    /** The window a figure covers, said next to it, because a figure whose window is unstated is a figure. */
    public static function window(Window $window): string
    {
        return $window->from->toDayDateTimeString().' to '.$window->to->toDayDateTimeString()
            .', '.strtolower(self::grainLabel($window->grain));
    }

    /** Whether a seam answered at all. Unbound removes exactly the claim it controls, and nothing else. */
    public static function seam(bool $bound, string $whenBound, string $whenUnbound): string
    {
        return $bound ? $whenBound : $whenUnbound;
    }

    /** A subject reference the erasure path has redacted, which is a fact rather than a blank. */
    public static function subject(string $subjectRef): string
    {
        return $subjectRef === '' ? 'Nobody named — the subject reference has been redacted' : $subjectRef;
    }
}

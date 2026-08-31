<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
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
use Liberu\Ecommerce\Reporting\Filament\Support\Render;

/*
 * Every `match` in Render is over a domain enum with no default arm. These walk
 * `cases()` rather than naming the cases, so a case the domain adds is found
 * here by the suite rather than on a screen by an operator.
 */

it('has a sentence for every refusal the domain can record', function (): void {
    foreach (RefusalReason::cases() as $reason) {
        $sentence = Render::refusal($reason);

        expect($sentence)->not->toBe('')
            // The point of the module, said in every one of them: an absent
            // number is never a nought.
            ->and($sentence)->not->toContain('0');
    }

    expect(count(RefusalReason::cases()))->toBe(6);
});

it('labels and colours every run, delivery, definition and subscription state', function (): void {
    foreach (RunState::cases() as $state) {
        expect(Render::runLabel($state))->not->toBe('')->and(Render::runColour($state))->not->toBe('');
    }

    foreach (DeliveryState::cases() as $state) {
        expect(Render::deliveryLabel($state))->not->toBe('')->and(Render::deliveryColour($state))->not->toBe('');
    }

    foreach (DefinitionState::cases() as $state) {
        expect(Render::definitionLabel($state))->not->toBe('')->and(Render::definitionColour($state))->not->toBe('');
    }

    foreach (SubscriptionState::cases() as $state) {
        expect(Render::subscriptionLabel($state))->not->toBe('')->and(Render::subscriptionColour($state))->not->toBe('');
    }
});

it('gives delivered, failed and refused three different colours', function (): void {
    // Two of the three are the host's silence. A shared colour would hide one
    // behind the other on every screen this package draws.
    $colours = array_map(Render::deliveryColour(...), DeliveryState::cases());

    expect(array_unique($colours))->toHaveCount(count(DeliveryState::cases()));
});

it('labels every grain, cadence and unit', function (): void {
    foreach (Grain::cases() as $grain) {
        expect(Render::grainLabel($grain))->not->toBe('');
    }

    foreach (Cadence::cases() as $cadence) {
        expect(Render::cadenceLabel($cadence))->not->toBe('');
    }

    foreach (Unit::cases() as $unit) {
        expect(Render::unitLabel($unit))->not->toBe('');
    }
});

it('renders money in minor units and says so, because the domain stores no exponent', function (): void {
    // Dividing by a hundred here would be this package deciding what a
    // currency's exponent is, which is arithmetic it exists not to do.
    expect(Render::measure(Measure::money(125_00, 'GBP')))->toBe('12500 GBP (minor units)');
});

it('renders a ratio as the two integers it was stored as, never as a percentage', function (): void {
    // The host held a conversion rate in decimal(5,2) percent, which clamps at
    // 999.99 and cannot say whether 3.00 was three percent or three hundredths.
    expect(Render::measure(Measure::ratio(3, 1000)))->toBe('3 / 1000');
});

it('renders a count and a duration as themselves', function (): void {
    expect(Render::measure(Measure::count(42)))->toBe('42')
        ->and(Render::measure(Measure::seconds(90)))->toBe('90 seconds');
});

it('names the window a figure covers, because the host named none', function (): void {
    $window = Window::endingAt(Carbon::parse('2026-08-31 12:00:00'), 7, Grain::Weekly);

    expect(Render::window($window))->toContain('weekly')
        ->and(Render::window($window))->toContain('2026');
});

it('names a refused definition with its version, because a result cites a version', function (): void {
    $refusal = new Refusal('net-revenue', 3, RefusalReason::NoSourceBound);

    expect(Render::refusalLine($refusal))->toStartWith('net-revenue v3 — ')
        ->and(Render::refusalLines([$refusal]))->toHaveCount(1)
        ->and(Render::refusalLines([]))->toBe([]);
});

it('says which of two things an unbound seam is', function (): void {
    expect(Render::seam(true, 'bound', 'unbound'))->toBe('bound')
        ->and(Render::seam(false, 'bound', 'unbound'))->toBe('unbound');
});

it('renders a redacted subject as a fact rather than as a blank', function (): void {
    expect(Render::subject(''))->toContain('redacted')
        ->and(Render::subject('person-1'))->toBe('person-1');
});

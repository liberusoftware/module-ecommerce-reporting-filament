<?php

declare(strict_types=1);

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\Reporting\Actions\ComputeReport;
use Liberu\Ecommerce\Reporting\Actions\DefineMetric;
use Liberu\Ecommerce\Reporting\Actions\SubscribeToReport;
use Liberu\Ecommerce\Reporting\Data\Measure;
use Liberu\Ecommerce\Reporting\Data\Reading;
use Liberu\Ecommerce\Reporting\Data\Window;
use Liberu\Ecommerce\Reporting\Enums\Cadence;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeDestination;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeSource;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestCase;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Reporting\Models\MetricDefinition;
use Liberu\Ecommerce\Reporting\Models\ReportRun;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        TestTenant::reset();
        PanelTenant::resolveUsing(fn (): string => TestTenant::current());

        // Load-bearing. Every seam is unbound as the domain ships it, and that
        // unbound state is behaviour this suite asserts: a test inheriting a
        // binding from the one before it would prove the opposite of what it
        // claims.
        Config::set('reporting.sources', []);
        Config::set('reporting.destination', null);

        // The snapshot is one read per request, and a test is not a request.
        Snapshot::forget();

        Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00'));
    })
    ->in(__DIR__.'/Feature');

// The rendering rules need a container to read a seam binding out of, and no
// database at all.
uses(TestCase::class)->in(__DIR__.'/Unit');

function bindSource(string $key, FakeSource $source): FakeSource
{
    Config::set('reporting.sources', array_merge((array) Config::get('reporting.sources'), [$key => $source]));

    return $source;
}

function bindDestination(?FakeDestination $destination = null): FakeDestination
{
    $destination ??= new FakeDestination();
    Config::set('reporting.destination', $destination);

    return $destination;
}

function defineMetric(
    string $tenantId = TestTenant::PRIMARY,
    string $name = 'net-revenue',
    string $sourceKey = 'orders',
    Unit $unit = Unit::Money,
    ?string $currency = 'GBP',
    Grain $grain = Grain::Daily,
): MetricDefinition {
    return (new DefineMetric())($tenantId, $name, $sourceKey, $unit, $grain, 30, $currency);
}

/** @param  list<int>  $minorUnits */
function money(array $minorUnits, string $currency = 'GBP'): array
{
    $readings = [];

    foreach ($minorUnits as $index => $value) {
        $readings[] = new Reading(Carbon::now()->copy()->subDays(count($minorUnits) - $index), Measure::money($value, $currency));
    }

    return $readings;
}

function windowOf(int $days = 30, Grain $grain = Grain::Daily): Window
{
    return Window::endingAt(Carbon::now(), $days, $grain);
}

function compute(string $tenantId = TestTenant::PRIMARY, int $days = 30): ReportRun
{
    (new ComputeReport())($tenantId, windowOf($days));

    Snapshot::forget();

    return ReportRun::query()->where('tenant_id', $tenantId)->orderByDesc('id')->firstOrFail();
}

/** @param  list<string>  $definitions */
function subscribe(
    string $tenantId = TestTenant::PRIMARY,
    string $name = 'weekly-digest',
    string $subjectRef = 'person-1',
    array $definitions = ['net-revenue'],
    Cadence $cadence = Cadence::Daily,
): void {
    (new SubscribeToReport())($tenantId, $name, $subjectRef, 'mailbox-1', $definitions, $cadence);

    Snapshot::forget();
}

/**
 * The notifications the last request sent, as title, body and colour.
 *
 * Filament's own `assertNotified()` compares the whole serialised notification,
 * so it fails on an icon this suite has no opinion about. Reading them consumes
 * them, because mounting the component drains the session.
 *
 * @return array{title: ?string, body: ?string, color: mixed}
 */
function lastNotification(): array
{
    $component = new Notifications();
    $component->mount();

    $sent = $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'color' => $notification->getColor(),
        ])
        ->values()
        ->all();

    return $sent === [] ? ['title' => null, 'body' => null, 'color' => null] : $sent[count($sent) - 1];
}

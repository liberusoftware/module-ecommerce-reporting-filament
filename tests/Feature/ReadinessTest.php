<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Reporting\Actions\ComputeReport;
use Liberu\Ecommerce\Reporting\Actions\DeliverRun;
use Liberu\Ecommerce\Reporting\Data\Window;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Enums\RunState;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Pages\Readiness;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeSource;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Reporting\Filament\Widgets\LatestRunOverview;
use Liberu\Ecommerce\Reporting\Filament\Widgets\OverdueDeliveries;
use Liberu\Ecommerce\Reporting\Models\ReportRun;
use Livewire\Livewire;
use RuntimeException;

/*
 * The page the host had and left empty: a merchant panel with a Reports
 * navigation item whose page declared no header widgets and rendered three
 * lines of Blade, while all eight widgets sat on the platform panel.
 *
 * It answers the question the runbook has to. Nothing reported because nothing
 * has run, because everything that ran was refused, or because there genuinely
 * is nothing to report — three different faults with one appearance.
 */

function readiness(): string
{
    return (string) Livewire::test(Readiness::class)->assertOk()->html();
}

it('carries both widgets, which is what the host’s empty Reports page did not', function (): void {
    expect((new Readiness())->getHeaderWidgets())->toBe([LatestRunOverview::class, OverdueDeliveries::class]);
});

it('says nothing is defined, which is the host: three revenues and nowhere to say which', function (): void {
    $html = readiness();

    expect($html)->toContain('Nothing is defined')
        ->and($html)->toContain('No definition names a source')
        ->and($html)->toContain('No run has ever been attempted')
        ->and($html)->toContain('Nobody is subscribed');
});

it('says of each source key whether anything answers it', function (): void {
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    defineMetric(name: 'orders-placed', sourceKey: 'nobody', unit: Unit::Count, currency: null);
    bindSource('orders', new FakeSource());

    $html = readiness();

    expect($html)->toContain('2 definitions across every version')
        ->and($html)->toContain('something is bound and will be asked')
        ->and($html)->toContain('nothing is bound, so every definition on this key is refused');
});

it('tells a module that has never run from one whose every run failed', function (): void {
    // Two different faults with one appearance in the host. Here they are two
    // sentences.
    defineMetric(sourceKey: 'orders');
    bindSource('orders', new FakeSource([], new RuntimeException('the warehouse is down')));

    expect(readiness())->toContain('No run has ever been attempted');

    // A run that refuses everything still succeeds as a run: it is the results
    // query that has no successful run to read, because it wrote no numbers.
    compute();

    expect(readiness())->toContain('refused 1 definitions');
});

it('says a run that asked for nothing had nothing to report, rather than that it failed', function (): void {
    (new ComputeReport())(TestTenant::PRIMARY, Window::endingAt(Carbon::now(), 30, Grain::Daily));
    Snapshot::forget();

    expect(readiness())->toContain('asked for nothing and refused nothing');
});

it('names the window and the counts of the newest successful run', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100, 200])));
    compute(days: 7);

    $html = readiness();

    expect($html)->toContain('obtained 2 numbers and refused 0 definitions')
        ->and($html)->toContain('daily');
});

it('counts the subscriptions that are overdue and the ones never attempted', function (): void {
    subscribe(name: 'weekly-digest');
    subscribe(name: 'monthly-digest', definitions: ['net-revenue']);

    expect(readiness())->toContain('2 subscriptions, of which 2 are overdue and 2 have never had a single attempt');
});

it('says every active subscription is inside its cadence when none is late', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));
    bindDestination();
    subscribe();

    $run = compute();
    (new DeliverRun())(TestTenant::PRIMARY, $run->id);
    Snapshot::forget();

    expect(readiness())->toContain('every active one inside its cadence');
});

it('reports every run attempt as failed when none of them finished', function (): void {
    // `ListRunResults` reads the newest run that succeeded, so a tenant whose
    // runs all failed has attempts and no result set.
    defineMetric();

    expect(Snapshot::runCount(TestTenant::PRIMARY))->toBe(0);

    compute();

    expect(Snapshot::runCount(TestTenant::PRIMARY))->toBe(1);
});

it('says a run was attempted and none succeeded, rather than that none was attempted', function (): void {
    // A run that failed partway wrote no results at all, so there is nothing to
    // read — and the host's command caught the exception, printed it and
    // returned into a console nobody read. This is that run, on a screen.
    ReportRun::query()->create([
        'tenant_id' => TestTenant::PRIMARY,
        'state' => RunState::Failed,
        'window_from' => Carbon::now()->subDays(7),
        'window_to' => Carbon::now(),
        'grain' => Grain::Daily,
        'requested' => 1,
        'refusals' => [],
        'started_at' => Carbon::now(),
        'finished_at' => Carbon::now(),
    ]);

    Snapshot::forget();

    expect(readiness())->toContain('1 runs have been attempted and none has succeeded');
});

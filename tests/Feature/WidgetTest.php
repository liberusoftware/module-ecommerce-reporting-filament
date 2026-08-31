<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Liberu\Ecommerce\Reporting\Actions\DeliverRun;
use Liberu\Ecommerce\Reporting\Actions\WithdrawMetric;
use Liberu\Ecommerce\Reporting\Enums\RefusalReason;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeSource;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Reporting\Filament\Widgets\LatestRunOverview;
use Liberu\Ecommerce\Reporting\Filament\Widgets\OverdueDeliveries;
use Liberu\Ecommerce\Reporting\Models\ReportSubscription;
use Livewire\Livewire;

/*
 * The suite the host did not have.
 *
 * `grep -rn 'Widgets\\' tests/` returned nothing across the whole host tree, so
 * `TopProductsWidget` — which selected from a derived table with no `store_id`
 * in it and raised an unknown-column error for every panel user whose team
 * owned a store — was unreachable from CI. A widget is also the one surface
 * Filament's own tenancy does not reach: `canView()` defaults to true and no
 * panel scope applies to what a widget queries.
 *
 * Every test here constructs a widget.
 */

it('mounts both widgets and renders them', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([1_250])));
    compute();
    subscribe();

    Livewire::test(LatestRunOverview::class)->assertOk();
    Livewire::test(OverdueDeliveries::class)->assertOk();
});

it('shows the refusal where a number would be, and never a nought', function (): void {
    // The decision the module exists for, on the surface that gets it wrong.
    // The host printed "0% increase" with an up arrow for a merchant's first
    // month and for a flat month alike.
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    defineMetric(name: 'orders-placed', sourceKey: 'nobody', unit: Unit::Count, currency: null);
    bindSource('orders', new FakeSource(money([1_250])));

    compute();

    $lines = Livewire::test(LatestRunOverview::class)->instance()->lines();

    expect($lines)->toBe([
        [
            'metric' => 'net-revenue v1',
            'value' => '1250 GBP (minor units)',
            'because' => 'obtained '.Carbon::now()->copy()->subDay()->toDayDateTimeString(),
        ],
        [
            'metric' => 'orders-placed v1',
            'value' => Render::NONE,
            'because' => Render::refusal(RefusalReason::NoSourceBound),
        ],
    ]);
});

it('says no run has ever succeeded rather than showing a screen of noughts', function (): void {
    defineMetric();

    expect(Livewire::test(LatestRunOverview::class)->instance()->lines())->toBe([[
        'metric' => 'No run',
        'value' => Render::NONE,
        'because' => 'no run has ever succeeded for this merchant, which is a different state from a run that had nothing to report',
    ]]);
});

it('names the window every figure covers, once, above them', function (): void {
    // The host let each of eight widgets compute its own `now()` and named none
    // of the windows on the page.
    defineMetric();
    bindSource('orders', new FakeSource(money([10])));
    $run = compute(days: 14);

    $widget = Livewire::test(LatestRunOverview::class)->instance();

    expect($widget->window())->toBe('Run '.$run->id.', covering '.Render::window($run->toWindow()))
        ->and($widget->window())->toContain('daily');
});

it('has no window to name when nothing has been computed', function (): void {
    expect(Livewire::test(LatestRunOverview::class)->instance()->window())
        ->toBe('Nothing has been computed, so there is no window to name.');
});

it('renders one merchant’s numbers when both merchants defined the identical metric', function (): void {
    // Two merchants both calling a metric `net-revenue` is the ordinary case,
    // and a widget query missing its tenant reads both.
    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        defineMetric(tenantId: $tenant);
    }

    bindSource('orders', new FakeSource(money([700])));
    compute(TestTenant::OTHER);
    Snapshot::forget();

    expect(Livewire::test(LatestRunOverview::class)->instance()->lines()[0]['metric'])->toBe('No run');

    TestTenant::use(TestTenant::OTHER);
    Snapshot::forget();

    expect(Livewire::test(LatestRunOverview::class)->instance()->lines()[0]['value'])->toBe('700 GBP (minor units)');
});

it('lists only this merchant’s overdue subscriptions, and gets the right non-zero number', function (): void {
    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        subscribe(tenantId: $tenant, name: 'weekly-digest');
    }

    $widget = Livewire::test(OverdueDeliveries::class);
    $mine = ReportSubscription::query()->where('tenant_id', TestTenant::PRIMARY)->pluck('id')->all();

    expect($widget->instance()->overdueIds())->toBe($mine)
        ->and($mine)->toHaveCount(1);

    $widget->assertCanSeeTableRecords(ReportSubscription::query()->whereIn('id', $mine)->get())
        ->assertCanNotSeeTableRecords(
            ReportSubscription::query()->where('tenant_id', TestTenant::OTHER)->get()
        );
});

it('shows nothing overdue once every active subscription has delivered inside its cadence', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([10])));
    bindDestination();
    subscribe();

    $run = compute();
    (new DeliverRun())(TestTenant::PRIMARY, $run->id);
    Snapshot::forget();

    expect(Livewire::test(OverdueDeliveries::class)->instance()->overdueIds())->toBe([]);
});

it('refuses to render at all when the panel has no merchant', function (): void {
    // `canView()` is true on every Filament widget by default, and a widget
    // carries no panel scope: this is the override that closes it.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(LatestRunOverview::canView())->toBeFalse()
        ->and(OverdueDeliveries::canView())->toBeFalse();

    PanelTenant::resolveUsing(fn (): string => TestTenant::current());

    expect(LatestRunOverview::canView())->toBeTrue()
        ->and(OverdueDeliveries::canView())->toBeTrue();
});

it('reads the domain once for the whole page rather than once per widget', function (): void {
    // The host composed eight widgets, five of which resolved AnalyticsService
    // independently and two of which ran the same six aggregates.
    defineMetric();
    bindSource('orders', new FakeSource(money([10])));
    compute();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $first = Snapshot::latest(TestTenant::PRIMARY);
    $after = $queries;
    $second = Snapshot::latest(TestTenant::PRIMARY);

    expect($after)->toBeGreaterThan(0)
        ->and($queries)->toBe($after)
        ->and($second)->toBe($first);
});

it('reads one clock for the whole request, so two widgets cannot report two windows', function (): void {
    // A page rendered across midnight showed the host's widgets reporting
    // different windows, with nothing on the page naming any of them.
    $first = Snapshot::asOf();

    Carbon::setTestNow(Carbon::now()->addHours(3));

    expect(Snapshot::asOf())->toEqual($first);

    Snapshot::forget();

    expect(Snapshot::asOf())->not->toEqual($first);
});

it('says a definition the run said nothing about at all is neither a number nor a refusal', function (): void {
    // The one place the module still lets silence look like an answer: a source
    // that answers successfully with no readings produces no result row and no
    // refusal, so the definition falls out of the run entirely. Filed as
    // `ecommerce-reporting#6`. An empty stat here would read as nought.
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    bindSource('orders', new FakeSource([]));

    compute();

    $lines = Livewire::test(LatestRunOverview::class)->instance()->lines();

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['metric'])->toBe('net-revenue v1')
        ->and($lines[0]['value'])->toBe(Render::NONE)
        ->and($lines[0]['because'])->toContain('no number and no refusal for it');
});

it('says the same of a definition minted after the run that is on the screen', function (): void {
    // Which is the honest reading: this run holds nothing about it, and the
    // domain does not publish enough to say which of the two reasons it is.
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    bindSource('orders', new FakeSource(money([1_250])));

    compute();

    defineMetric(name: 'orders-placed', sourceKey: 'orders', unit: Unit::Count, currency: null);
    Snapshot::forget();

    $lines = Livewire::test(LatestRunOverview::class)->instance()->lines();

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['value'])->toBe('1250 GBP (minor units)')
        ->and($lines[1]['metric'])->toBe('orders-placed v1')
        ->and($lines[1]['because'])->toContain('was not in the run');
});

it('drops a withdrawn definition from the latest numbers rather than showing it as missing', function (): void {
    // A withdrawn definition produces no further results by design, so listing
    // it as one this run said nothing about would be a refusal that is not one.
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    bindSource('orders', new FakeSource(money([1_250])));

    compute();

    (new WithdrawMetric())(TestTenant::PRIMARY, 'net-revenue', 1);
    Snapshot::forget();

    expect(Snapshot::activeDefinitions(TestTenant::PRIMARY))->toBe([]);

    // The number it did obtain is still on the screen, because the result cites
    // the version rather than the row.
    expect(Livewire::test(LatestRunOverview::class)->instance()->lines()[0]['value'])
        ->toBe('1250 GBP (minor units)');
});

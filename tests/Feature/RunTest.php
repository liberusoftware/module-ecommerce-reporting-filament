<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Enums\RefusalReason;
use Liberu\Ecommerce\Reporting\Enums\RunState;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\Pages\ListRuns;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\Pages\ViewRun;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RelationManagers\ResultsRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RunResource;
use Liberu\Ecommerce\Reporting\Filament\Support\Render;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeDestination;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeSource;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Reporting\Models\ReportRun;
use Livewire\Livewire;
use RuntimeException;

/*
 * A run is the only place a number and the reason another number is missing sit
 * beside each other. The host had neither.
 */

it('computes by hand, because the package schedules nothing', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100, 250])));

    Livewire::test(ListRuns::class)->callAction('compute', ['days' => '14', 'grain' => Grain::Daily->value]);

    $said = lastNotification();

    expect($said['title'])->toBe('It ran')
        ->and($said['body'])->toContain('asked 1 definitions')
        ->and($said['body'])->toContain('wrote 2 numbers')
        ->and($said['body'])->toContain('Nothing was refused.')
        ->and($said['color'])->toBe('success')
        ->and(ReportRun::query()->firstOrFail()->state)->toBe(RunState::Succeeded);
});

it('names every definition it refused and why, in the sentence it says afterwards', function (): void {
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    defineMetric(name: 'orders-placed', sourceKey: 'nobody', unit: Unit::Count, currency: null);
    bindSource('orders', new FakeSource(money([100])));

    Livewire::test(ListRuns::class)->callAction('compute', ['days' => '30', 'grain' => Grain::Daily->value]);

    $said = lastNotification();

    expect($said['title'])->toBe('It ran, and refused some of it')
        ->and($said['body'])->toContain('It refused 1: orders-placed v1')
        ->and($said['body'])->toContain(Render::refusal(RefusalReason::NoSourceBound))
        // It obtained something, so it is not a failure — merely incomplete.
        ->and($said['color'])->toBe('success');
});

it('calls a run that obtained nothing at all a failure, rather than a page of noughts', function (): void {
    defineMetric(sourceKey: 'nobody');

    Livewire::test(ListRuns::class)->callAction('compute', ['days' => '30', 'grain' => Grain::Monthly->value]);

    $said = lastNotification();

    expect($said['color'])->toBe('danger')
        ->and($said['body'])->toContain('wrote 0 numbers')
        ->and($said['body'])->toContain('monthly');
});

it('shows the numbers a run obtained beside the definitions it refused', function (): void {
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    defineMetric(name: 'basket-size', sourceKey: 'orders', unit: Unit::Ratio, currency: null);
    bindSource('orders', new FakeSource(money([100])));

    $run = compute();
    $page = (string) Livewire::test(ViewRun::class, ['record' => $run->getKey()])->assertOk()->html();

    // The relation manager is its own Livewire component, so the numbers are
    // rendered there and the refusals on the run above them.
    $numbers = (string) Livewire::test(ResultsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewRun::class,
    ])->assertOk()->html();

    expect($numbers)->toContain('100 GBP (minor units)')
        ->and($page)->toContain('basket-size v1')
        // The whole series refused rather than half of it coerced.
        ->and($page)->toContain('not the unit this definition declares');
});

it('says nothing was refused rather than leaving the section blank', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));

    $run = compute();

    expect((string) Livewire::test(ViewRun::class, ['record' => $run->getKey()])->html())
        ->toContain('Nothing was refused');
});

it('lists runs with the window each one covered', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));
    compute();

    expect((string) Livewire::test(ListRuns::class)->assertOk()->html())->toContain('daily');
});

it('delivers a run to every active subscription, and records the ones that refused', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));
    subscribe();

    $run = compute();

    // Nothing bound to receive it: the attempt is a row saying so, not an
    // attempt that was quietly never made.
    Livewire::test(ViewRun::class, ['record' => $run->getKey()])->callAction('deliver');

    $said = lastNotification();

    expect($said['title'])->toBe('Delivered')
        ->and($said['body'])->toContain('attempt 1: refused, because ')
        ->and($said['body'])->toContain('nothing is bound to receive a result set')
        ->and($said['color'])->toBe('warning');
});

it('says what the destination said when one is bound', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));
    bindDestination();
    subscribe();

    $run = compute();

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])->callAction('deliver');

    $said = lastNotification();

    expect($said['title'])->toBe('Delivered')
        ->and($said['body'])->toContain('attempt 1: delivered')
        ->and($said['color'])->toBe('success');
});

it('tells an empty attempt list from a failed delivery', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));

    $run = compute();

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])->callAction('deliver');

    $said = lastNotification();

    expect($said['title'])->toBe('Nothing to deliver')
        ->and($said['body'])->toContain('no active subscription')
        ->and($said['color'])->toBe('warning');
});

it('records a delivery that raised as failed rather than losing it', function (): void {
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));
    bindDestination(new FakeDestination('', new RuntimeException('the mailbox is full')));
    subscribe();

    $run = compute();

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])->callAction('deliver');

    expect(lastNotification()['body'])->toContain('attempt 1: failed');
});

it('offers no delivery on a run that has not succeeded', function (): void {
    // There is nothing to send from a run that did not finish, and a control
    // that sends nothing is a control that lied about what it did.
    $run = ReportRun::query()->create([
        'tenant_id' => TestTenant::PRIMARY,
        'state' => RunState::Running,
        'window_from' => Carbon::now()->subDays(7),
        'window_to' => Carbon::now(),
        'grain' => Grain::Daily,
        'requested' => 0,
        'refusals' => [],
        'started_at' => Carbon::now(),
    ]);

    Livewire::test(ViewRun::class, ['record' => $run->getKey()])
        ->assertActionHidden('deliver')
        ->assertOk();
});

it('drops a refusal row it cannot read rather than rendering half of one', function (): void {
    // The refusals are a JSON column. A row whose reason is not one the domain
    // publishes is not a refusal this panel can explain, and inventing a
    // sentence for it would be the fault the module exists to prevent.
    $run = ReportRun::query()->create([
        'tenant_id' => TestTenant::PRIMARY,
        'state' => RunState::Succeeded,
        'window_from' => Carbon::now()->subDays(7),
        'window_to' => Carbon::now(),
        'grain' => Grain::Daily,
        'requested' => 1,
        'refused' => 1,
        'refusals' => [['name' => 'net-revenue', 'version' => 1, 'reason' => 'something-else']],
        'started_at' => Carbon::now(),
        'finished_at' => Carbon::now(),
    ]);

    expect(Render::refusalLines($run->refusalList()))->toBe([]);
});

it('takes its outcome filter from the domain enum', function (): void {
    expect(array_keys(RunResource::stateOptions()))->toBe(array_column(RunState::cases(), 'value'));
});

it('says a run wrote no numbers rather than showing an empty table', function (): void {
    defineMetric(sourceKey: 'nobody');

    $run = compute();

    expect((string) Livewire::test(ResultsRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => ViewRun::class,
    ])->assertOk()->html())->toContain('not a run of noughts');
});

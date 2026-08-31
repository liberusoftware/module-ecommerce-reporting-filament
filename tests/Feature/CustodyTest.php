<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Liberu\Ecommerce\Reporting\Actions\DeliverRun;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages\ListDefinitions;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages\ViewDefinition;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\Pages\ViewRun;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RelationManagers\ResultsRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RunResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeSource;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Reporting\Models\MetricDefinition;
use Liberu\Ecommerce\Reporting\Models\ReportResult;
use Liberu\Ecommerce\Reporting\Models\ReportRun;
use Liberu\Ecommerce\Reporting\Models\ReportSubscription;
use Livewire\Livewire;

/*
 * Two merchants with deliberately identical values: the same metric name, the
 * same source key, the same subscription name, the same destination. Two
 * merchants both defining `net-revenue` is the ordinary case, and a proof that
 * creates one merchant's rows proves nothing about a `where` nobody wrote.
 *
 * Every relation on every screen is exercised, not only every list: tenancy has
 * leaked through relations rather than queries in four consecutive waves, and a
 * surface is where `withCount()` gets reached for.
 */

/** @return array{ours: array{run: ReportRun, definition: MetricDefinition, subscription: ReportSubscription}, theirs: array{run: ReportRun, definition: MetricDefinition, subscription: ReportSubscription}} */
function twoMerchants(): array
{
    bindSource('orders', new FakeSource(money([100, 200])));
    bindDestination();

    $rows = [];

    foreach ([TestTenant::PRIMARY, TestTenant::OTHER] as $tenant) {
        defineMetric(tenantId: $tenant);
        subscribe(tenantId: $tenant);
        $run = compute($tenant);
        (new DeliverRun())($tenant, $run->id);

        $rows[] = [
            'run' => $run,
            'definition' => MetricDefinition::query()->where('tenant_id', $tenant)->firstOrFail(),
            'subscription' => ReportSubscription::query()->where('tenant_id', $tenant)->firstOrFail(),
        ];
    }

    Snapshot::forget();

    return ['ours' => $rows[0], 'theirs' => $rows[1]];
}

/** @return Collection<int, Model> */
function relationRecords(string $manager, Model $owner, string $pageClass): Collection
{
    $records = Livewire::test($manager, [
        'ownerRecord' => $owner,
        'pageClass' => $pageClass,
    ])->instance()->getTable()->getRecords();

    /** @var Collection<int, Model> $rows */
    $rows = $records instanceof Collection ? $records : Collection::make($records->items());

    return $rows;
}

it('lists only this merchant’s definitions, runs and subscriptions', function (): void {
    $rows = twoMerchants();

    Livewire::test(ListDefinitions::class)
        ->assertCanSeeTableRecords(MetricDefinition::query()->whereKey($rows['ours']['definition']->getKey())->get())
        ->assertCanNotSeeTableRecords(MetricDefinition::query()->whereKey($rows['theirs']['definition']->getKey())->get());

    expect(DefinitionResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and(RunResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and(SubscriptionResource::getEloquentQuery()->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('counts through every restated relation, and gets the right non-zero number', function (): void {
    // `withCount()` builds the relation from a fresh instance whose `tenant_id`
    // is null. Unguarded, the restatement becomes `where('tenant_id', '')` and
    // reports zero for everything, which looks exactly like isolation working.
    $rows = twoMerchants();

    $run = RunResource::getEloquentQuery()->firstOrFail();
    $subscription = SubscriptionResource::getEloquentQuery()->firstOrFail();

    // Against this merchant's own rows rather than a literal, so the proof stays
    // about the guard and not about how many buckets one fake source returns.
    $numbers = ReportResult::query()->where('tenant_id', TestTenant::PRIMARY)->count();

    expect($numbers)->toBeGreaterThan(0)
        ->and($run->results_count)->toBe($numbers)
        ->and($subscription->deliveries_count)->toBe(1);
});

it('shows only this run’s numbers and this subscription’s attempts', function (): void {
    $rows = twoMerchants();

    $results = relationRecords(ResultsRelationManager::class, $rows['ours']['run'], ViewRun::class);
    $deliveries = relationRecords(DeliveriesRelationManager::class, $rows['ours']['subscription'], ViewSubscription::class);

    $numbers = ReportResult::query()->where('tenant_id', TestTenant::PRIMARY)->count();

    expect($results)->toHaveCount($numbers)
        ->and($numbers)->toBeGreaterThan(0)
        ->and($results->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY])
        ->and($deliveries)->toHaveCount(1)
        ->and($deliveries->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('answers another merchant’s record exactly as it answers nobody’s', function (): void {
    // Not a 403 and not a different message: the panel is not a directory of
    // the deployment, so "belongs to somebody else" and "does not exist" are
    // one answer.
    $rows = twoMerchants();

    $definition = fn (int|string $key): mixed => Livewire::test(ViewDefinition::class, ['record' => $key]);
    $run = fn (int|string $key): mixed => Livewire::test(ViewRun::class, ['record' => $key]);
    $subscription = fn (int|string $key): mixed => Livewire::test(ViewSubscription::class, ['record' => $key]);

    expect(fn (): mixed => $definition($rows['theirs']['definition']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $definition(99999))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $run($rows['theirs']['run']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $run(99999))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $subscription($rows['theirs']['subscription']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $subscription(99999))->toThrow(ModelNotFoundException::class);
});

it('follows the panel’s merchant when it changes, rather than the first one it saw', function (): void {
    $rows = twoMerchants();

    TestTenant::use(TestTenant::OTHER);
    Snapshot::forget();

    Livewire::test(ListDefinitions::class)
        ->assertCanSeeTableRecords(MetricDefinition::query()->whereKey($rows['theirs']['definition']->getKey())->get())
        ->assertCanNotSeeTableRecords(MetricDefinition::query()->whereKey($rows['ours']['definition']->getKey())->get());
});

it('refuses to resolve a panel with no merchant rather than matching orphan rows', function (): void {
    // `where('tenant_id', null)` compiles to `is null`, which lists exactly the
    // orphan rows a scope exists to hide.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => '');

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): int => 7);

    expect(PanelTenant::current())->toBe('7');
});

it('has no merchant to fall back to when the host names no resolver', function (): void {
    // A panel with no Filament tenancy and no resolver has no merchant to be.
    // There is no "show everything" to fall back to.
    PanelTenant::resolveUsing(null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);
});

it('keeps a definition in one merchant’s hands even when both define the identical one', function (): void {
    $rows = twoMerchants();

    expect($rows['ours']['definition']->name)->toBe($rows['theirs']['definition']->name)
        ->and(DefinitionResource::canView($rows['ours']['definition']))->toBeTrue()
        ->and(DefinitionResource::canView($rows['theirs']['definition']))->toBeFalse()
        ->and(RunResource::canView($rows['ours']['run']))->toBeTrue()
        ->and(RunResource::canView($rows['theirs']['run']))->toBeFalse()
        ->and(SubscriptionResource::canView($rows['ours']['subscription']))->toBeTrue()
        ->and(SubscriptionResource::canView($rows['theirs']['subscription']))->toBeFalse();
});

<?php

declare(strict_types=1);

use Liberu\Ecommerce\Reporting\Actions\WithdrawMetric;
use Liberu\Ecommerce\Reporting\Enums\DefinitionState;
use Liberu\Ecommerce\Reporting\Enums\Grain;
use Liberu\Ecommerce\Reporting\Enums\Unit;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages\ListDefinitions;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\Pages\ViewDefinition;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeSource;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Reporting\Models\MetricDefinition;
use Livewire\Livewire;

/*
 * The screen the host had nowhere to put. It computed revenue three ways on one
 * dashboard — net of refunds, gross of refunds, and off a different column
 * again — and nothing in the tree said which a figure was.
 */

it('shows what each number is, and whether anybody answers for it', function (): void {
    defineMetric(name: 'net-revenue', sourceKey: 'orders');
    defineMetric(name: 'orders-placed', sourceKey: 'nobody', unit: Unit::Count, currency: null);
    bindSource('orders', new FakeSource());

    $html = (string) Livewire::test(ListDefinitions::class)->assertOk()->html();

    expect($html)->toContain('net-revenue')
        ->and($html)->toContain('orders-placed')
        ->and($html)->toContain('orders')
        ->and($html)->toContain('nobody');
});

it('says on the record itself whether the source key is bound', function (): void {
    // Visible before a run refuses, rather than inferred from a run that did.
    $bound = defineMetric(name: 'net-revenue', sourceKey: 'orders');
    $unbound = defineMetric(name: 'orders-placed', sourceKey: 'nobody', unit: Unit::Count, currency: null);
    bindSource('orders', new FakeSource());

    expect(DefinitionResource::binding($bound))->toContain('Something is bound')
        ->and(DefinitionResource::binding($unbound))->toContain('refuses this definition')
        ->and(DefinitionResource::binding($unbound))->toContain('not a zero');
});

it('renders one definition, bound and unbound alike', function (): void {
    $bound = defineMetric(sourceKey: 'orders');
    bindSource('orders', new FakeSource());

    Livewire::test(ViewDefinition::class, ['record' => $bound->getKey()])->assertOk();

    $unbound = defineMetric(name: 'orders-placed', sourceKey: 'nobody', unit: Unit::Count, currency: null);

    Livewire::test(ViewDefinition::class, ['record' => $unbound->getKey()])->assertOk();
});

it('mints the next version rather than editing the one results already cite', function (): void {
    defineMetric(name: 'net-revenue');

    Livewire::test(ListDefinitions::class)->callAction('define', [
        'name' => 'net-revenue',
        'source_key' => 'orders',
        'unit' => Unit::Money->value,
        'currency' => 'gbp',
        'grain' => Grain::Weekly->value,
        'window_days' => '7',
    ]);

    $said = lastNotification();
    $versions = MetricDefinition::query()->where('name', 'net-revenue')->pluck('version')->all();

    expect($versions)->toBe([1, 2])
        ->and($said['title'])->toBe('Defined')
        ->and($said['body'])->toContain('version 2')
        ->and($said['color'])->toBe('success')
        // Upper-cased on the way in: money carries an ISO 4217 code.
        ->and(MetricDefinition::query()->where('version', 2)->firstOrFail()->currency)->toBe('GBP');
});

it('carries no currency on a metric that is not money', function (): void {
    // The domain refuses a currency on anything but money, so the form does not
    // offer one and the action does not send one.
    Livewire::test(ListDefinitions::class)->callAction('define', [
        'name' => 'orders-placed',
        'source_key' => 'orders',
        'unit' => Unit::Count->value,
        'grain' => Grain::Daily->value,
        'window_days' => '30',
    ]);

    $definition = MetricDefinition::query()->where('name', 'orders-placed')->firstOrFail();

    expect($definition->currency)->toBeNull()
        ->and($definition->unit)->toBe(Unit::Count)
        ->and(lastNotification()['title'])->toBe('Defined');
});

it('withdraws a definition instead of deleting it', function (): void {
    $definition = defineMetric();

    Livewire::test(ViewDefinition::class, ['record' => $definition->getKey()])->callAction('withdraw');

    $said = lastNotification();

    expect($definition->refresh()->state)->toBe(DefinitionState::Withdrawn)
        ->and($said['title'])->toBe('Withdrawn')
        ->and($said['body'])->toContain('still cite it')
        ->and($said['color'])->toBe('success');
});

it('withdraws nothing the second time, and says so rather than saying it did', function (): void {
    // The control is not hidden on a withdrawn definition. Whether a
    // definition may be withdrawn is the domain's rule, and a control the panel
    // hid is not a control — the row a page is looking at may have moved.
    $definition = defineMetric();
    $page = Livewire::test(ViewDefinition::class, ['record' => $definition->getKey()]);

    (new WithdrawMetric())(TestTenant::PRIMARY, $definition->name, $definition->version);

    $page->callAction('withdraw');

    $said = lastNotification();

    expect($said['title'])->toBe('Nothing happened')
        ->and($said['body'])->toContain('No active definition')
        ->and($said['color'])->toBe('danger');
});

it('takes every option on every select and filter from a domain enum', function (): void {
    expect(array_keys(DefinitionResource::stateOptions()))->toBe(array_column(DefinitionState::cases(), 'value'))
        ->and(array_keys(DefinitionResource::unitOptions()))->toBe(array_column(Unit::cases(), 'value'))
        ->and(array_keys(DefinitionResource::grainOptions()))->toBe(array_column(Grain::cases(), 'value'));
});

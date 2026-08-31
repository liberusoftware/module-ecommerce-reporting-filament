<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User;
use Liberu\Ecommerce\Reporting\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\Reporting\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\Reporting\Filament\Pages\Readiness;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RelationManagers\ResultsRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RunResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\PanelTenant;
use Liberu\Ecommerce\Reporting\Models\MetricDefinition;

/*
 * The ability matrix, asserted by name.
 *
 * A missing policy is permissive, and a policy that exists but lacks the method
 * asked about is also permissive, because Filament falls through to `allow()`.
 * The only way to know an ability is closed is to ask it and be told no.
 *
 * A delete here would not merely be wrong, it would fatal: results and
 * deliveries raise from a `deleting` hook and a definition raises from both
 * `updating` and `deleting`. An ability that fatals instead of refusing is
 * still an ability that was offered.
 */

$resources = [[DefinitionResource::class], [RunResource::class], [SubscriptionResource::class]];

$managers = [[ResultsRelationManager::class], [DeliveriesRelationManager::class]];

it('publishes no create, no edit and no delete on a record the domain will not change', function (string $resource): void {
    $record = new MetricDefinition();

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($record))->toBeFalse()
        ->and($resource::canDelete($record))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and($resource::canForceDelete($record))->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse()
        ->and($resource::canReorder())->toBeFalse()
        ->and($resource::canReplicate($record))->toBeFalse()
        // None of these tables soft-deletes, so a restore could restore nothing.
        ->and($resource::canRestore($record))->toBeFalse()
        ->and($resource::canRestoreAny())->toBeFalse();
})->with($resources);

it('registers no create page and no edit page on any resource', function (): void {
    expect(array_keys(DefinitionResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(RunResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(SubscriptionResource::getPages()))->toBe(['index', 'view']);
});

it('refuses to view anything at all when the panel has no merchant', function (): void {
    $definition = defineMetric();

    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(DefinitionResource::canViewAny())->toBeFalse()
        ->and(DefinitionResource::canView($definition))->toBeFalse()
        ->and(RunResource::canViewAny())->toBeFalse()
        ->and(SubscriptionResource::canViewAny())->toBeFalse()
        ->and(Readiness::canAccess())->toBeFalse();
});

it('answers a model that is not its own with no', function (): void {
    // `canView` takes Filament's `Model`. A resource that assumed its own model
    // would fatal rather than refuse.
    expect(DefinitionResource::canView(new User()))->toBeFalse()
        ->and(RunResource::canView(new User()))->toBeFalse()
        ->and(SubscriptionResource::canView(new User()))->toBeFalse();
});

it('closes every relation-manager ability by name, including associate and dissociate', function (string $manager): void {
    /** @var object $instance */
    $instance = new $manager();
    $record = new MetricDefinition();

    // `canAssociate` and `canDissociate` are live on a `hasMany` and default
    // open. Dissociating a result from its run moves a number under a
    // different window, with no edit form and no audit row.
    expect($instance->canAssociate())->toBeFalse()
        ->and($instance->canDissociate($record))->toBeFalse()
        ->and($instance->canDissociateAny())->toBeFalse()
        ->and($instance->canAttach())->toBeFalse()
        ->and($instance->canDetach($record))->toBeFalse()
        ->and($instance->canDetachAny())->toBeFalse()
        ->and($instance->canCreate())->toBeFalse()
        ->and($instance->canEdit($record))->toBeFalse()
        ->and($instance->canDelete($record))->toBeFalse()
        ->and($instance->canDeleteAny())->toBeFalse()
        ->and($instance->canForceDelete($record))->toBeFalse()
        ->and($instance->canForceDeleteAny())->toBeFalse()
        ->and($instance->canReorder())->toBeFalse()
        ->and($instance->canReplicate($record))->toBeFalse()
        ->and($instance->canRestore($record))->toBeFalse()
        ->and($instance->canRestoreAny())->toBeFalse()
        ->and($instance->canView($record))->toBeFalse()
        // The one ability published, stated rather than inherited.
        ->and($instance->canViewAny())->toBeTrue();
})->with($managers);

it('names no method after a Filament ability outside the two concerns that close them', function (string $class): void {
    // A subclass method wins over a trait's, so a method named for an ability
    // would silently reopen it.
    $abilities = array_diff(
        array_merge(
            get_class_methods(DeniesUnpublishedResourceAbilities::class),
            get_class_methods(DeniesUnpublishedRelationAbilities::class),
        ),
        // The two each resource states and answers for itself.
        ['canView', 'canViewAny'],
    );

    // A trait's methods report the using class as their declaring class, so the
    // file is what separates "this class wrote it" from "the trait did".
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $declared = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $file,
        ),
    );

    expect(array_intersect($declared, $abilities))->toBe([]);
})->with(array_merge($resources, $managers));

it('applies a closing concern to every resource and relation manager the plugin registers', function (): void {
    // A new screen cannot arrive open: the concern is what makes an ability
    // nobody thought about closed rather than allowed.
    foreach ([DefinitionResource::class, RunResource::class, SubscriptionResource::class] as $resource) {
        expect(in_array(DeniesUnpublishedResourceAbilities::class, class_uses($resource), true))->toBeTrue();
    }

    foreach ([ResultsRelationManager::class, DeliveriesRelationManager::class] as $manager) {
        expect(in_array(DeniesUnpublishedRelationAbilities::class, class_uses($manager), true))->toBeTrue();
    }
});

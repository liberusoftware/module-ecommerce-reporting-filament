<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Liberu\Ecommerce\Reporting\Actions\DeliverRun;
use Liberu\Ecommerce\Reporting\Actions\ForgetSubjectAcrossTenants;
use Liberu\Ecommerce\Reporting\Enums\Cadence;
use Liberu\Ecommerce\Reporting\Enums\SubscriptionState;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\RelationManagers\DeliveriesRelationManager;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;
use Liberu\Ecommerce\Reporting\Filament\Support\Snapshot;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeDestination;
use Liberu\Ecommerce\Reporting\Filament\Tests\Fakes\FakeSource;
use Liberu\Ecommerce\Reporting\Filament\Tests\TestTenant;
use Liberu\Ecommerce\Reporting\Models\ReportSubscription;
use Livewire\Livewire;
use RuntimeException;

/*
 * A subscription that has never delivered is a countable row rather than an
 * absence, and this screen is where it is counted.
 */

function subscription(string $name = 'weekly-digest'): ReportSubscription
{
    return ReportSubscription::query()->where('name', $name)->firstOrFail();
}

function deliverOnce(): void
{
    defineMetric();
    bindSource('orders', new FakeSource(money([100])));

    $run = compute();
    (new DeliverRun())(TestTenant::PRIMARY, $run->id);

    Snapshot::forget();
}

it('says a subscription has never been attempted rather than leaving the cell blank', function (): void {
    subscribe();

    expect(SubscriptionResource::standing(subscription()))->toContain('Never attempted')
        ->and(SubscriptionResource::overdue(subscription()))->toBeTrue()
        ->and((string) Livewire::test(ListSubscriptions::class)->assertOk()->html())->toContain('Never attempted');
});

it('says a subscription is inside its cadence once it has delivered', function (): void {
    subscribe();
    bindDestination();
    deliverOnce();

    expect(SubscriptionResource::standing(subscription()))->toBe('Delivered inside its cadence.')
        ->and(SubscriptionResource::overdue(subscription()))->toBeFalse();
});

it('says a delivered subscription is overdue once its cadence has passed', function (): void {
    subscribe(cadence: Cadence::Daily);
    bindDestination();
    deliverOnce();

    Carbon::setTestNow(Carbon::now()->addDays(3));
    Snapshot::forget();

    expect(SubscriptionResource::standing(subscription()))->toStartWith('Overdue. Last delivered ');
});

it('says an attempt that refused did not deliver, rather than counting it as one', function (): void {
    // No destination bound: the attempt is a row saying refused, and the
    // subscription is still one that has never delivered.
    subscribe();
    deliverOnce();

    expect(SubscriptionResource::standing(subscription()))->toContain('did not deliver: refused')
        ->and(SubscriptionResource::standing(subscription()))->toContain('Nothing has reached the destination');
});

it('says an attempt that raised did not deliver either', function (): void {
    subscribe();
    bindDestination(new FakeDestination('', new RuntimeException('the mailbox is full')));
    deliverOnce();

    expect(SubscriptionResource::standing(subscription()))->toContain('did not deliver: failed');
});

it('expects nothing of a paused subscription, and does not count it late', function (): void {
    subscribe();

    Livewire::test(ViewSubscription::class, ['record' => subscription()->getKey()])->callAction('pause');

    $said = lastNotification();
    Snapshot::forget();

    expect(subscription()->state)->toBe(SubscriptionState::Paused)
        ->and($said['title'])->toBe('Moved')
        ->and($said['color'])->toBe('success')
        ->and(SubscriptionResource::standing(subscription()))->toContain('Paused, so nothing is expected')
        ->and(SubscriptionResource::overdue(subscription()))->toBeFalse();
});

it('resumes a paused subscription and starts counting it again', function (): void {
    subscribe();

    $page = Livewire::test(ViewSubscription::class, ['record' => subscription()->getKey()]);
    $page->callAction('pause');

    Livewire::test(ViewSubscription::class, ['record' => subscription()->getKey()])
        ->assertActionHidden('pause')
        ->callAction('resume');

    Snapshot::forget();

    expect(subscription()->state)->toBe(SubscriptionState::Active)
        ->and(lastNotification()['body'])->toContain('is now active');
});

it('puts no subject reference on the listing, and names a redacted one on the record', function (): void {
    // Wave 11 shipped reviewer PII on a public listing. Picking a subscription
    // out of a list needs its name and its destination and nothing else.
    subscribe(subjectRef: 'person-1');

    expect((string) Livewire::test(ListSubscriptions::class)->html())->not->toContain('person-1');

    $record = (string) Livewire::test(ViewSubscription::class, ['record' => subscription()->getKey()])->html();

    expect($record)->toContain('person-1');

    (new ForgetSubjectAcrossTenants())('person-1');
    Snapshot::forget();

    expect((string) Livewire::test(ViewSubscription::class, ['record' => subscription()->getKey()])->html())
        ->toContain('redacted')
        ->not->toContain('person-1');
});

it('lists every attempt including the ones that refused', function (): void {
    subscribe();
    deliverOnce();

    $html = (string) Livewire::test(DeliveriesRelationManager::class, [
        'ownerRecord' => subscription(),
        'pageClass' => ViewSubscription::class,
    ])->assertOk()->html();

    expect($html)->toContain('nothing is bound to receive a result set');
});

it('says nothing has ever been sent rather than showing an empty table', function (): void {
    subscribe();

    expect((string) Livewire::test(DeliveriesRelationManager::class, [
        'ownerRecord' => subscription(),
        'pageClass' => ViewSubscription::class,
    ])->assertOk()->html())->toContain('Nothing has ever been sent');
});

it('says a subscription naming no definition receives an empty slice', function (): void {
    subscribe(definitions: []);

    expect((string) Livewire::test(ViewSubscription::class, ['record' => subscription()->getKey()])->html())
        ->toContain('receives an empty slice');
});

it('takes its state and cadence filters from domain enums', function (): void {
    expect(array_keys(SubscriptionResource::stateOptions()))->toBe(array_column(SubscriptionState::cases(), 'value'))
        ->and(array_keys(SubscriptionResource::cadenceOptions()))->toBe(array_column(Cadence::cases(), 'value'));
});

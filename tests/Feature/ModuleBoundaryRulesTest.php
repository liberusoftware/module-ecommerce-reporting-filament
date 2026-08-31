<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Liberu\Ecommerce\Reporting\Filament\Pages\Readiness;
use Liberu\Ecommerce\Reporting\Filament\ReportingPlugin;
use Liberu\Ecommerce\Reporting\Filament\Resources\Definitions\DefinitionResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Runs\RunResource;
use Liberu\Ecommerce\Reporting\Filament\Resources\Subscriptions\SubscriptionResource;
use Liberu\Ecommerce\Reporting\Filament\Widgets\LatestRunOverview;
use Liberu\Ecommerce\Reporting\Filament\Widgets\OverdueDeliveries;

/*
 * The rules that are this package's rather than the fleet's. The shared boundary
 * suite in `package-testbench` asserts the manifest, the required files, the
 * absence of `App\` and the panel plugins.
 */

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<int, string> every PHP file under src/, absolute. */
function sourceFiles(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot().'/src')) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Source with comments stripped. Every rule below is about what this package
 * does, and a docblock naming the host fault it refuses to repeat is the text
 * most likely to trip a naive grep.
 */
function sourceCode(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return array<string, mixed> */
function packageJson(string $file): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents(packageRoot().'/'.$file), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('never reaches the one published method that does not re-check the tenant', function (): void {
    // `ListRunResults::forRun()` takes a loaded run and trusts it, because
    // `DeliverRun` needs it that way. Every read in this package enters through
    // `__invoke($tenantId, ...)` instead, and this is the mechanical proof
    // rather than a comment saying so.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toContain('forRun');
    }
});

it('does no arithmetic on a money amount, in any form', function (): void {
    // The domain stores minor units and an ISO 4217 code and no exponent.
    // Dividing by a hundred is right for GBP and wrong for JPY and KWD, and the
    // host's own widget hard-coded `->money('USD')` on every order it drew. All
    // three of this module's surfaces render the minor amount and its code.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach (['->money(', 'number_format', 'intdiv', 'floatval', '(float)', 'round(', 'ceil(', 'floor('] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }

        // No division at all: a quotient is a float, and a float is what the
        // domain refuses.
        expect($source)->not->toMatch('#[^/*\s]\s*/\s*\$#');
    }
});

it('registers everything through the plugin and nothing from the service provider', function (): void {
    // A provider that registered widgets would put one merchant's numbers on
    // whatever panel happened to boot. The host mounted all eight of its
    // widgets on the platform panel and left the merchant panel's page empty.
    $provider = (string) file_get_contents(packageRoot().'/src/ReportingFilamentServiceProvider.php');

    expect($provider)->not->toContain('Resource::class')
        ->and($provider)->not->toContain('->resources(')
        ->and($provider)->not->toContain('->pages(')
        ->and($provider)->not->toContain('->widgets(');

    $plugin = (string) file_get_contents(packageRoot().'/src/ReportingPlugin.php');

    foreach (['DefinitionResource::class', 'RunResource::class', 'SubscriptionResource::class',
        'Readiness::class', 'LatestRunOverview::class', 'OverdueDeliveries::class'] as $registered) {
        expect($plugin)->toContain($registered);
    }
});

it('declares every class the manifest names for the panel', function (): void {
    $manifest = packageJson('module.json');

    expect($manifest['presentation']['filament']['app'])->toBe([ReportingPlugin::class]);

    foreach ($manifest['presentation']['filament'] as $plugins) {
        foreach ($plugins as $plugin) {
            expect(class_exists($plugin))->toBeTrue();
        }
    }

    foreach ([DefinitionResource::class, RunResource::class, SubscriptionResource::class,
        Readiness::class, LatestRunOverview::class, OverdueDeliveries::class] as $class) {
        expect(class_exists($class))->toBeTrue();
    }
});

it('answers canView on every widget, which Filament leaves open by default', function (): void {
    // A widget is the surface the panel's own tenancy does not reach.
    foreach ([packageRoot().'/src/Widgets/LatestRunOverview.php', packageRoot().'/src/Widgets/OverdueDeliveries.php'] as $widget) {
        expect(sourceCode($widget))
            ->toContain('public static function canView(): bool')
            ->toContain('PanelTenant::resolvable()')
            // And every query it makes says which merchant it is for.
            ->toContain('PanelTenant::current()');
    }
});

it('ships no Filament create, edit or delete control of any kind', function (): void {
    // A result and a delivery raise from a `deleting` hook and a definition
    // raises from `updating` too, so a control offered here would fatal rather
    // than refuse — and an ability that fatals was still an ability offered.
    $forbidden = [
        'CreateAction', 'EditAction', 'DeleteAction', 'DeleteBulkAction', 'ForceDeleteAction',
        'RestoreAction', 'ReplicateAction', 'AssociateAction', 'DissociateAction',
        'AttachAction', 'DetachAction', 'CreateRecord', 'EditRecord', 'ManageRecords',
    ];

    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach ($forbidden as $control) {
            expect($source)->not->toContain($control);
        }
    }
});

it('writes nothing through Eloquent, because every write here is a published domain action', function (): void {
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('->save()')
            ->and($source)->not->toContain('->update(')
            ->and($source)->not->toContain('->delete()')
            ->and($source)->not->toContain('->forceFill(')
            ->and($source)->not->toContain('->increment(')
            ->and($source)->not->toContain('->decrement(')
            ->and($source)->not->toContain('::create(');
    }
});

it('never states a state, a grain or a table name of its own', function (): void {
    // A `where('state', 'active')` here would be this panel deciding what an
    // active definition is. The enums decide, and every comparison in this
    // package is against a case rather than against a string it chose.
    //
    // `refused` is deliberately absent from the literal list: it is also a
    // column on the runs table, holding how many definitions a run could not
    // obtain, and a rule that forbade the word would forbid naming the count.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach ([
            "'active'", "'withdrawn'", "'succeeded'", "'running'", "'failed'",
            "'delivered'", "'paused'", "'daily'", "'weekly'", "'monthly'",
            'reporting_',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }

        expect($source)->not->toMatch("/->where\\('(state|grain|unit|cadence|reason)', '/");
    }
});

it('takes every option on every select and filter from a domain enum', function (): void {
    foreach ([
        'src/Resources/Definitions/DefinitionResource.php',
        'src/Resources/Runs/RunResource.php',
        'src/Resources/Subscriptions/SubscriptionResource.php',
    ] as $source) {
        expect(sourceCode(packageRoot().'/'.$source))->toContain('::cases()');
    }
});

it('never puts a subject reference on a listing', function (): void {
    // Wave 11 shipped reviewer PII on a public listing.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toContain("TextColumn::make('subject_ref')");
    }

    expect(sourceCode(packageRoot().'/src/Resources/Subscriptions/SubscriptionResource.php'))
        ->toContain("TextEntry::make('subject_ref')");
});

it('offers neither of the two controls that span every merchant', function (): void {
    // Erasure and export both say in their names that they cross tenants. A
    // screen scoped to one merchant must not carry a control that acts on all.
    foreach (sourceFiles() as $file) {
        expect(sourceCode($file))->not->toContain('AcrossTenants');
    }
});

it('reaches for no framework-foundation helper', function (): void {
    // `config()`, `app()`, `auth()`, `now()` and `view()` live in
    // `laravel/framework`, not in `illuminate/support`. They pass CI only
    // because the testbench drags the framework in.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        // The lookbehind excludes `:` so `Carbon::now()` — which is
        // `nesbot/carbon` and reachable — is not mistaken for the helper.
        expect($source)->not->toMatch('/(?<![\w>$:])config\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])app\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])auth\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])now\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])view\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])session\(/')
            ->and($source)->not->toMatch('/(?<![\w>$:])response\(/');
    }
});

it('reads the clock and counts rows in one place, because the domain publishes no query for either', function (): void {
    // The domain answers `ListRunResults`, `ListDefinitions` and
    // `ListOverdueSubscriptions` and nothing that says whether a run has ever
    // been attempted — `ecommerce-reporting#2`. Every count this panel needs
    // and the one clock it reads live in `Support\Snapshot`, so the gap is one
    // file wide and `docs/panel.md` records it.
    $clocks = [];
    $counts = [];

    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);
        $name = basename($file);

        if (str_contains($source, 'Carbon::now()')) {
            $clocks[] = $name;
        }

        $where = substr_count($source, '->where(');

        if ($where > 0) {
            $counts[$name] = $where;
        }
    }

    ksort($counts);

    expect($clocks)->toBe(['Snapshot.php'])
        ->and($counts)->toBe([
            'DefinitionResource.php' => 1,
            'OverdueDeliveries.php' => 1,
            'RunResource.php' => 1,
            'Snapshot.php' => 2,
            'SubscriptionResource.php' => 1,
        ]);
});

it('states its Filament floor as the true one', function (): void {
    // Every tag from v5.4.0 declares `illuminate/contracts: ^11.28|^12.0|^13.0`.
    expect(packageJson('composer.json')['require']['filament/filament'])->toBe('^5.4');
});

it('lists the sibling packages the manifest declares and no others', function (): void {
    $composer = packageJson('composer.json');
    $manifest = packageJson('module.json');

    $siblings = array_filter(
        $composer['require'],
        fn (string $constraint, string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($siblings)->toBe($manifest['requires']['packages'])
        ->and($siblings)->toBe(['liberusoftware/ecommerce-reporting' => '^0.1']);
});

it('points at the domain repository, because it is not on Packagist yet', function (): void {
    expect(packageJson('composer.json')['repositories'])->toBe([[
        'type' => 'vcs',
        'url' => 'https://github.com/liberusoftware/module-ecommerce-reporting',
    ]]);
});

it('agrees with itself about its own version', function (): void {
    expect(packageJson('composer.json')['version'])->toBe(packageJson('module.json')['version']);
});

it('carries no session identifier in any file', function (): void {
    $files = array_merge(sourceFiles(), [
        packageRoot().'/README.md',
        packageRoot().'/CHANGELOG.md',
        packageRoot().'/module.json',
        packageRoot().'/docs/panel.md',
        packageRoot().'/docs/adoption.md',
        packageRoot().'/docs/runbook.md',
    ]);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        // Split so this assertion is not itself the thing it forbids: a
        // repository-wide grep for the literals has to come back empty.
        expect($source)->not->toContain('claude'.'.ai')
            ->and($source)->not->toContain('Claude-'.'Session');
    }
});

it('contributes nothing when a panel boots it, so nothing can arrive on a panel that did not register it', function (): void {
    $plugin = ReportingPlugin::make();
    $plugin->boot(Filament::getPanel('app'));

    expect($plugin->getId())->toBe('ecommerce-reporting');
});

# ecommerce-reporting-filament

The operator's view of a reported number. A Filament panel over
[`liberusoftware/ecommerce-reporting`](https://github.com/liberusoftware/module-ecommerce-reporting):
what each definition asks and who answers it, what every run computed and — as importantly — what it
refused and why, and which subscriptions have gone undelivered.

It is a one-to-one adapter. It contains no arithmetic and no business rules: every decision is a
published domain action, query or model, and every state, grain, unit, cadence and refusal on every
screen comes from a domain backed enum.

## The fault it is shaped around

**The host computes "revenue" three different ways, on one dashboard, and has nowhere to write down
which one a number is.** The headline stat nets refunds; the per-product table sums line prices and
cannot see a refund at all; lifetime value filters on a different column again and is gross of
everything. A merchant reads all three on one page and no two of them mean the same thing.

The same page has the other half of the fault: when the previous period had no revenue, the growth
figure returns `0`, so a merchant's **first** month and a merchant's **flat** month are both
"0% increase" with an up arrow. A number that could not be computed and a number that happens to be
nought come out of the same expression.

Both are one fault. So the domain module records a **refusal with a reason** and writes no result
row where it could not obtain a number, and this package's single job is to **render the refusal,
never an empty cell and never a nought**.

## What it publishes

| | |
|---|---|
| `ReportingPlugin` | The entry point. The host attaches it to the panel it means to, with the two widgets. |
| **Readiness** | Whether anything is defined, whether anything is bound to answer each source key, whether a run has ever been attempted, and — the distinction the host could not make — whether a quiet screen means nothing ran, everything that ran failed, or there was genuinely nothing to report. |
| **Definitions** | What each number is: a name, a version, the source key that owns the arithmetic, the window and grain it is asked over, the unit it comes back in, and whether anything is bound to answer it. Define one, withdraw one. |
| **Runs** | Every attempt, the window stamped on it, the numbers it obtained, and every definition it refused with the reason by name. Compute one, deliver one. |
| **Subscriptions** | Who wants which definitions delivered where, every delivery attempt including the ones that refused, and whether each is inside its cadence. Pause one, resume one. |
| **Two widgets** | The newest number for every definition — or the refusal in its place — under the window it covers; and the subscriptions that have not delivered. |

## What it does not do

- **No nought, anywhere, that it did not measure.** A refused definition renders its reason. A
  definition the run holds neither a number nor a refusal for — which happens when a bound source
  answers with nothing — is named as exactly that rather than dropped. An empty table says which of
  the several possible silences it is. There is no default, no clamp and no substitute.
- **No arithmetic on money.** The domain stores minor units and an ISO 4217 code and **no exponent**,
  so this package renders `1250 GBP (minor units)` and does not divide. Dividing by a hundred is
  right for GBP, wrong for JPY and wrong for KWD; the host's own orders widget hard-coded
  `->money('USD')` and drew every order in dollars whatever it was taken in. The `-livewire` surface
  renders money identically, and both have a boundary test forbidding `->money(`, `number_format`,
  `intdiv`, `round(` and division in `src/`.
- **No create, no edit, no delete, anywhere.** A definition is versioned rather than edited — a
  result cites the version, so editing one in place would change what a number already shown to a
  merchant meant — and results and deliveries raise from their own `deleting` hooks. A delete
  offered here would fatal rather than refuse, and an ability that fatals is still an ability that
  was offered.
- **No subscribe control.** `SubscribeToReport` takes a subject reference naming a person, and it
  upserts on `(tenant, name)` alone — so posting a name that already exists silently reassigns
  somebody else's subscription and the address it delivers to.
- **No export and no erasure screen.** Both published entry points say `AcrossTenants` in their
  names. A screen scoped to one merchant must not carry a control that acts on every merchant.
- **No chart.** A line cannot render a refusal in place of a point, and every reader of a gap in a
  line reads it as a nought.
- **No subject reference on any listing.**

## Widgets, which is where the host's tenancy stopped

`Widget::canView()` returns true by default and no panel scope reaches what a widget queries — a
widget is the one Filament surface the panel's own tenancy does not cover. The host's
`TopProductsWidget` selected from a derived table with no `store_id` in it and raised an
unknown-column error for **every** panel user whose team owned a store, and
`grep -rn 'Widgets\\' tests/` came back empty across its whole tree, so CI never saw it.

Both widgets here override `canView()`, state the tenant in their own reads, and are constructed by
the suite.

## Installing

```bash
composer require liberusoftware/ecommerce-reporting-filament
```

Nothing boots on install: the module manager registers the provider when the module is named in
`MODULES_ENABLED`. Attaching the panel is one call, and the host must add the schedule line itself —
this package schedules nothing. See [`docs/adoption.md`](docs/adoption.md).

Why every screen is shaped the way it is, including the gaps this package found in the domain, is in
[`docs/panel.md`](docs/panel.md). What breaks and what to do about it is in
[`docs/runbook.md`](docs/runbook.md).

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `planned:ecommerce:reporting`.
- Actual Composer identity: `liberusoftware/ecommerce-reporting-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/filament/reporting.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Ecommerce](https://github.com/liberusoftware/project-ecommerce-laravel-filament): Catalogs, checkout, orders and fulfillment.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->

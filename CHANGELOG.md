# Changelog

## 0.1.0

The Filament presentation package for `liberusoftware/ecommerce-reporting`. It presents that module
and no other, and contains no arithmetic of its own.

### What it puts on a panel

- **Definitions** — what each number is: a name, a version, the source key that owns the arithmetic,
  the window and grain it is asked over, the unit it comes back in, and whether anything is bound to
  answer it. Defining a metric mints a version; withdrawing one is the only change a definition
  accepts.
- **Runs** — every attempt, with the window stamped on it, the numbers it obtained and the
  definitions it refused with their reasons. The numbers hang off the run as a relation; the
  refusals are on the run itself, because a refused definition has no result row.
- **Subscriptions** — who wants which definitions delivered where, every delivery attempt including
  the ones that refused, and whether the subscription is inside its cadence.
- **Readiness** — the screen that separates "has never run" from "every run failed" from "nothing to
  report".
- **Two widgets** — the latest number for every definition, or the refusal in its place; and the
  subscriptions that have not delivered.

### Decisions

- **A refusal renders as a refusal.** The single decision this package exists to carry. A definition
  the run could not obtain has no result row, and the widget shows its reason where a value would
  be. Nothing here writes, formats or defaults a nought.
- **Money is shown in minor units and said to be.** The domain stores minor units and an ISO 4217
  code and no exponent. Dividing by a hundred here would be this package deciding what a currency's
  exponent is.
- **A ratio is the two integers it was stored as.** Never a percentage.
- **One clock, one read, per request.** `Support\Snapshot` reads `Carbon::now()` once and the domain
  queries once, and every screen and both widgets share the answer.
- **Widgets state the tenant.** `Widget::canView()` defaults to true and no panel scope reaches a
  widget's query, so both widgets answer `PanelTenant::resolvable()` and both queries carry the
  tenant. The suite constructs both.
- **A definition the run said nothing about is a sentence, not an absence.** A source that answers
  successfully with no readings produces neither a result nor a refusal
  (`ecommerce-reporting#6`), so the widget starts from every active definition rather than from the
  run's rows, and names the two possibilities it cannot separate. The Readiness page does the same.
- **`ListRunResults::forRun()` is not reachable from this package.** It is the one published method
  that takes a loaded run and does not re-check custody. Every entry here is
  `__invoke($tenantId, ...)`, and the delivery control asks `CustodyPolicy::ownsRun` before it hands
  a run id on. A boundary test asserts the method name appears nowhere in `src/`.

### Deliberately not shipped

- **No subscribe control.** `SubscribeToReport` takes a subject reference naming a person, and a
  panel that typed one in would be minting an identity this module is not the custodian of. It also
  upserts on `(tenant, name)` alone, so posting a name that already exists silently reassigns
  somebody else's subscription and its destination (`ecommerce-reporting#4`). The host creates
  subscriptions; this panel pauses, resumes and reads them. A boundary test asserts the class name
  appears nowhere in `src/`.
- **No export and no erasure control.** `ExportSubjectRecordAcrossTenants` and
  `ForgetSubjectAcrossTenants` both span every tenant, by name and by design. A screen scoped to one
  merchant must not carry a control that acts on all of them.
- **No chart.** The domain returns buckets and a chart over them would be honest, but a chart cannot
  render a refusal in place of a point, and every reader of a gap in a line reads it as a nought.
  A table and a stat can say why a number is absent; a line cannot.

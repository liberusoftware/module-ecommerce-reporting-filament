# What this panel shows, and what it refuses to show

An adapter over `liberusoftware/ecommerce-reporting`. It contains no arithmetic and no rule about
what a number means. Everything on every screen came out of a published action, query or model of
that package.

## The one decision

**A number the module could not obtain has no result row, and this panel renders the refusal in the
place where the number would have been.** Never a nought, never a blank cell, never a dash on its
own.

That is the whole subject of the module. The host computed revenue three ways on one dashboard —
net of refunds for the headline, gross of refunds for the per-product table, and off a different
column again for lifetime value — and had nowhere to record which one a figure was. It also printed
"0% increase" with an up arrow when the previous period had no revenue at all, so a merchant's first
month and a merchant's flat month were the same sentence. Both faults are one fault: an absent
number and a number that happens to be nought coming out of the same expression.

So:

| Where | What it shows instead of a nought |
|---|---|
| `LatestRunOverview` | one stat per definition; a refused one carries the reason as its value's description and `—` as its value |
| `RunResource` list | a `Because` column listing each refusal by name, version and reason |
| `RunResource` infolist | a section of its own, whose placeholder is *"Nothing was refused"* rather than empty |
| `ResultsRelationManager` | an empty state saying the run wrote no numbers and that the refusals are on the run |
| `DefinitionResource` | whether the definition's source key is bound, on the record, before any run refuses it |
| `SubscriptionResource` | never delivered, did not deliver, overdue and inside its cadence as four different sentences |
| `Pages\Readiness` | which definitions the newest run holds neither a number nor a refusal for, named |

## Money

`Render::measure` renders a money amount as **the integer minor amount, its ISO 4217 code, and the
words `(minor units)`** — `1250 GBP (minor units)`. It does not divide.

The domain stores minor units and a currency code and **no exponent**
(`ecommerce-reporting#1`). Dividing by a hundred is correct for GBP, wrong for JPY, which has no
minor unit, and wrong for KWD, which has three. Filament's `->money()` column would do exactly that
and would do it confidently; the host's own `RecentOrdersWidget` hard-coded `->money('USD')` and
rendered every order in dollars whatever currency it was taken in.

The `-livewire` surface of this module renders money identically, and both packages have a boundary
test forbidding `->money(`, `number_format`, `intdiv`, `round(`, `(float)` and division anywhere in
`src/`. Three surfaces of a module built to stop a number meaning three things must not each pick
their own representation.

A ratio is rendered as the two integers it was stored as — `3 / 1000` — for the same reason and
because the domain refuses a percentage: the host held a conversion rate in `decimal(5,2)` percent,
which clamps at 999.99.

## Tenancy

`Support\PanelTenant` is the only place the merchant is named. Every domain call takes it as a first
argument, and there is no fallback to "no tenant": `where('tenant_id', null)` compiles to `is null`
and lists exactly the orphan rows a scope exists to hide.

**A widget is the surface Filament's own tenancy does not reach.** `Widget::canView()` returns true
by default and no panel scope applies to what a widget queries. Both widgets here override
`canView()` with `PanelTenant::resolvable()` and state the tenant in their own reads, and the suite
constructs both — which is the test the host did not have. `grep -rn 'Widgets\\' tests/` came back
empty across its whole tree, which is why its `TopProductsWidget` raised an unknown-column error for
every panel user whose team owned a store without CI ever noticing.

`ListRunResults::forRun()` is the single published method that takes a loaded run and does not
re-check the tenant; `DeliverRun` needs it that way. **The string `forRun` appears nowhere in this
package's `src/`**, asserted mechanically rather than promised in a comment.

## One read, one clock

`Support\Snapshot` reads `Carbon::now()` once per request and each domain query once per tenant, and
the page and both widgets share the answer. The host composed eight widgets, five of which resolved
`AnalyticsService` independently, two of which ran the same six aggregates, and every one of which
called `now()` for itself — so a page rendered across midnight reported different windows and named
none of them. Every figure here is shown beside the window it covers.

The memo is keyed by tenant and lives for one request. It is reset by `Snapshot::forget()` after
every write and in the suite's `beforeEach`.

## Gaps in the domain package this panel worked around, and how

- **`ecommerce-reporting#1` — a money `Measure` carries no exponent.** Answered by not dividing, as
  above. This is the right answer for a panel too: an operator reading a reporting screen needs to
  know which currency and which magnitude, and a wrong decimal point is worse than an unfamiliar
  one.
- **`ecommerce-reporting#2` — no published query lists a tenant's runs.** A Filament resource is
  bound to an Eloquent model by construction, so `RunResource` reads `Models\ReportRun` and states
  the tenant in `getEloquentQuery()`; that is the fleet's established pattern and the boundary suite
  exempts a presentation package from the model rule it applies to an `-api` one. What the gap did
  cost is `Snapshot::runCount()` and `Snapshot::subscriptionCount()`, two counts that exist only
  because the domain publishes nothing that answers "has a run ever been attempted". They are two
  lines in one file rather than a `where` spread across three screens.
- **`ecommerce-reporting#3` — `CustodyPolicy::subjectMayRead` guards a query that does not exist.**
  Not used here. This panel is an operator's, not a subscriber's.
- **`ecommerce-reporting#4` — `SubscribeToReport` upserts on `(tenant, name)` alone.** A second
  caller silently reassigns an existing subscription's `subject_ref` and `destination_ref`, so a
  delivery keeps running and starts going somewhere else. This package ships no control that reaches
  that action, and a boundary test asserts the class name appears nowhere in `src/`. That is a
  stronger reason than the one this panel already had for shipping no subscribe form.
- **`ecommerce-reporting#5` — `Data\Definition` carries no `state`.** It does not block this
  package: `ListDefinitions` takes an `activeOnly` flag, which is how `Snapshot::activeDefinitions()`
  knows which definitions are still asked for, and the Definitions listing reads `Models\
  MetricDefinition`, which does carry the state. It would block anything reading the DTO alone.
- **`ecommerce-reporting#6` — a source that answers successfully with no readings produces neither a
  result nor a refusal.** The one place the module still lets silence look like an answer, and the
  exact failure §4.2 exists to prevent. The widget therefore starts from every active definition and
  says, of one the run holds neither for, *"this run holds no number and no refusal for it: either
  the bound source answered with nothing over this window, which the domain records as neither, or
  the definition was not in the run"* — the two possibilities, because the domain does not publish
  enough to separate them. The Readiness page names the same definitions and the same ambiguity.
- **`CustodyPolicy::ownsDefinition` and `ownsSubscription` are keyed on a name, not on a row.**
  `unique(tenant_id, name)` makes a name unique *within* a merchant, so two merchants may both hold
  `net-revenue`, and `ownsDefinition('tenant-a', 'net-revenue')` answers true while looking at
  tenant B's row. Both are the right question for a caller that has only a name and the wrong one
  for a caller that has a record. `canView()` therefore compares the record's own `tenant_id` on
  those two resources, and uses `CustodyPolicy::ownsRun()` — which *is* keyed on the row — on runs.
  Worth a fourth issue on the domain repository.

## Deliberately not shipped

- **No subscribe control.** Two reasons. `SubscribeToReport` takes a subject reference naming a
  person, and a form that typed one in would mint an identity this module is not the custodian of;
  and the action upserts on `(tenant, name)` alone, so posting a name that already exists silently
  reassigns somebody else's subscription (`ecommerce-reporting#4`).
- **No export and no erasure control.** Both published entry points say `AcrossTenants` in their
  names. A screen scoped to one merchant must not carry a control that acts on every merchant; a
  boundary test forbids the string.
- **No chart.** The domain returns buckets and a line over them would be honest, but a line cannot
  render a refusal in place of a point, and every reader of a gap in a line reads it as a nought.
- **No create, edit or delete anywhere.** A definition is versioned rather than edited, and results
  and deliveries raise from their own `deleting` hooks — a delete offered here would fatal rather
  than refuse.

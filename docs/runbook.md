# Runbook

## Nothing is on the screen

Open **Reporting → Readiness**. It separates the three faults that look identical everywhere else.

| It says | What is wrong | What to do |
|---|---|---|
| *Nothing is defined* | No metric definition exists for this merchant. There is no question to ask | Define one on the Definitions screen |
| *nothing is bound, so every definition on this key is refused* | A definition names a source key the host has bound nothing to | Bind it in `config/reporting.php`, or withdraw the definition |
| *No run has ever been attempted* | The host never added the schedule line | Add it (see `adoption.md`), or press **Compute now** |
| *N runs have been attempted and none has succeeded* | Every run raised partway. Each is on the Runs screen with the state it ended in | Read the run's row; a run that fails writes no results at all, by design |
| *asked for nothing and refused nothing* | A run happened and there was genuinely nothing to report | Nothing. This is the healthy empty state |

The last two rows are the distinction the host could not make and this module exists to make.

## A number is missing from the widget

It is not missing. Look at the stat for that definition: its value is `—` and its description is the
reason. The six reasons and what each means:

| Reason | What happened |
|---|---|
| `NoSourceBound` | Nothing is bound to that definition's source key. Nobody was asked |
| `SourceUnavailable` | The bound source was asked and raised. The number is unknown, not nought |
| `UnitMismatch` | The source answered in a shape that is not the declared unit. The whole series was refused rather than half of it coerced |
| `WindowNotSupported` | The source cannot answer over that window or grain, and said so rather than answering over a different one |
| `DefinitionWithdrawn` | The definition was withdrawn and was named in the run anyway |
| `NoDestinationBound` | Delivery only. Nothing is bound to receive a result set |

**If you find a nought on any screen in this package where a refusal belongs, that is a defect in
this package, not in the data.** File it.

## A number changed meaning

It did not. A result stores the definition **name and version** as columns rather than a foreign key,
so editing a definition cannot reach a number already computed. Redefining a metric mints the next
version and leaves the old one addressable — the Definitions screen lists every version, and a
withdrawn one sits beside the version that replaced it.

If two numbers for the same metric disagree, compare the versions on the run's results. Two versions
is the answer, not a bug.

## A subscription has gone quiet

The **Subscriptions that have not delivered** widget is the list. Every attempt is a row, including
the ones that refused, so this is a query rather than a log grep. The four sentences:

- *Never attempted* — no run has been delivered since the subscription was created. Check whether
  anything is calling `DeliverRun`, or press **Deliver this run** on a succeeded run.
- *did not deliver: refused* — nothing is bound to `reporting.destination`.
- *did not deliver: failed* — the bound destination raised. What it said is on the attempt row, kept
  verbatim.
- *Overdue. Last delivered …* — it delivered once and has not since, longer ago than its cadence.

A **paused** subscription is not on this list and is not counted late. That is deliberate.

## A merchant sees another merchant's numbers

They cannot, and if they do it is one of two places:

1. `PanelTenant` resolved the wrong merchant. It is the only place the tenant is named; check the
   panel provider's `tenantUsing()`.
2. A new screen was added without a tenant predicate in `getEloquentQuery()`, or a widget was added
   without `canView()`. `CustodyTest` and `ModuleBoundaryRulesTest` are what catch that, and both
   should have gone red before this reached a deployment.

There is no third place. Nothing in this package writes through Eloquent and every read states the
tenant.

## Deleting something

You cannot, from here, and you should not from anywhere. A definition is withdrawn, not deleted;
results and deliveries raise from a `deleting` hook. The panel offers no delete because an ability
that fatals instead of refusing is still an ability that was offered.

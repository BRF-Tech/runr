# Billing

The Billing module calculates what every project costs per month and who pays for it. It reads the
usage RunR meters anyway, prices it with the prices you set per region, and closes one bill per
project and calendar month. The payer of a project's bill is the project's **owner**.

RunR **only calculates**. It takes no payments, issues no invoices, sends no reminders and never
restricts anything because a bill is unpaid. Your invoicing system — an accounting package, an ERP,
a shop — reads the bills and the usage through the [billing API](#api-reference), receives
[signed events](#webhook-events) when something happens, and writes two things back on each bill:
a **payment link** and the **paid** status.

```
 RunR                                                         your invoicing system
 ─────────────────────────────────────────────────            ───────────────────────────────────
 metering (every 5 min, always on) ──┐
 prices per region (with dates) ─────┼─▶ bill per project ──▶ bill.closed ─────▶ invoice the owner
 fixed resources (approved) ─────────┘    and month           GET /api/billing/bills    collect payment
                                                                                         │
                         payment link, paid ◀── PUT /api/billing/bills/{bill}/payment-url ┘
                                            ◀── PUT /api/billing/bills/{bill}/paid
```

Billing works on a single-tenant installation and on a multi-tenant one. With tenancy, the platform
also bills each tenant for what its projects use in the regions the platform shares with it, and
each tenant bills its own projects, with a markup of its choice.

- [What billing is](#what-billing-is)
- [Turning billing on](#turning-billing-on)
- [Bills](#bills)
- [Prices](#prices)
- [Region billing types](#region-billing-types)
- [Fixed resources](#fixed-resources)
- [Metering and the fixed bill](#metering-and-the-fixed-bill)
- [Billing without tenancy](#billing-without-tenancy)
- [Billing with tenancy](#billing-with-tenancy)
- [Project stop and resume](#project-stop-and-resume)
- [API reference](#api-reference)
- [Webhook events](#webhook-events)
- [Example: invoicing a closed month](#example-invoicing-a-closed-month)
- [In the console](#in-the-console)
- [Permissions](#permissions)
- [Configuration reference](#configuration-reference)
- [Scheduled jobs and tables](#scheduled-jobs-and-tables)
- [Upgrade from 0.5.7](#upgrade-from-057)

---

## What billing is

| Term | Meaning |
|---|---|
| **Bill** | What one payer owes for one calendar month. A **project bill** covers one project; an **account bill** covers the resources of one user that belong to no project; a **tenant bill** (multi-tenant) covers what a tenant owes the platform. |
| **Owner** | The payer of a project's bills. A new project's owner is the user who created it; the owner can be transferred. |
| **Period** | A calendar month (`2026-10`), in the application time zone (UTC by default). |
| **Open / closed** | A bill is `open` while its month runs: its amounts are an estimate, calculated on request. After the month ends a scheduled job closes it: its line items and totals become final and are never calculated again. |
| **Billing type** | Every region has exactly one: `payg` (pay as you go: usage is billed at the end of the month) or `fixed` (a project requests fixed resources, pays a monthly price for them, prorated by day). |
| **Price version** | A complete set of prices for a region (or the default prices) with the date it takes effect. |
| **Markup** | Multi-tenant: the percentage a tenant adds to the platform's prices when it bills its projects for a shared platform region. |

With billing **off** (the default) nothing of this exists: metering keeps running and records the
usage of every project, but no prices are applied and no bills are made — the behaviour of earlier
releases.

---

## Turning billing on

Billing is the Billing module of the API, switched on like the Ticket module.

**Helm** ([Helm values](helm-values.md#billing)):

```yaml
billing:
  enabled: true
  currency: EUR                                         # every price and bill of the installation
  webhookUrl: https://invoicing.example.com/hooks/runr  # optional; empty: events are only kept
  webhookSecret: "<random string, at least 16 characters>"
```

**Docker Compose** ([Installation](install.md#billing)): `BILLING_ENABLED=true` in `compose/.env`
before you build the images, and `BILLING_CURRENCY`, `BILLING_WEBHOOK_URL`,
`BILLING_WEBHOOK_SECRET` in `compose/api.env`.

**Console.** The published UI images are built with `VITE_BILLING_ENABLED=true`. The console asks
the API at start whether billing is on (`GET /api/branding` → `features.billing`) and shows the
billing pages only then, so the same image serves installations with and without billing. A UI
image of your own needs the build flag `VITE_BILLING_ENABLED=true` to contain the billing screens
([UI build flags](modules.md#ui-build-flags)).

On the first start with billing on:

- the billing tables exist (they are created at every start, like the Ticket module's, also while
  the module is off; with tenancy every tenant schema gets them with `tenancy:migrate`, which the
  upgrade's migration Job and the bootstrap Job run);
- the platform's default prices are seeded with the [default values](#price-fields), so every
  region is priced from the first minute;
- every region is `payg` until you set another type;
- every project gets an open bill for the current month.

Then set the prices of your regions (Settings → Billing → Regions & prices), choose the billing type
of each region, and give your invoicing system an [API token](#api-tokens).

### Turning billing off and on

Switching billing off again (`billing.enabled: false`) removes the billing pages and API and stops
the bills; metering keeps recording usage and approved fixed resources stay approved. No money is
charged for the days billing was off:

- while billing is on, RunR records the day every hour (`billing-heartbeat`) and whenever bills are
  made or closed; while it is off, the scheduler records each day it runs (`billing-off-marker`);
- when billing comes back, the bills that were open before it went off end with its last active
  day and close with the next run of `billing-close`; a month that lies entirely in the off period
  gets no bill; the bills of the month billing came back in start on that day;
- usage metered and fixed resources held while billing was off are never billed.

A month in which billing was turned off and on again therefore has one bill before and one after
the off period, each with its days in `period_start` / `period_end`:

| Billing | Bills |
|---|---|
| on 1–5 October, off 6–19 October, on again 20 October | October: 1–5 Oct (closed on 21 Oct) and 20–31 Oct |
| on until 25 September, off until 2 November, on again 3 November | September: 1–25 Sep; October: none; November: 3–30 Nov |

Days on which no scheduler ran at all — an outage of the whole installation — are no off period: the
close job catches up as described in [One bill per project and month](#one-bill-per-project-and-month).
A project deleted or moved to another region while billing is off ends its fixed allocation on that
day as usual ([Fixed resources](#what-a-fixed-project-may-use)), and region offers made to tenants
meanwhile need no acceptance ([Sharing a region](#sharing-a-region-offer-and-acceptance)).

---

## Bills

### Who pays

The **owner** of a project pays its bills. A project created through the console, the CLI or the API
is owned by the user who created it. The owner can hand the project to another user — the owner
themselves or anyone with `projects.manage_access` on the project — in the project's settings or
with `PUT /api/projects/{project}/owner`.

The new owner must be a **member of the project's organization** who can see the project through
their own role, a group or project access (`projects.view`). A platform administrator's access to
every organization does not count: a tenant cannot make a platform administrator the payer of its
project (or show their name to its members) unless they are a member who sees the project. On the
platform's own projects of a multi-tenant installation (no organization) the owners are platform
administrators. The new owner gets a notification.

**The bill of the running month belongs to whoever owns the project when the month closes.** A
transfer moves every open bill — the whole running month, including the days before the transfer —
to the new owner; the bills already closed keep the owner they were closed with (name and e-mail
are stored on the closed bill). Transfer at the start of a month when the previous owner should pay
the days they owned the project. A project without owner — created by the system, such as demo
projects — gets bills without owner ("Unassigned" in the rollup) until an owner is set.

### One bill per project and month

Every project has one bill per calendar month — also a project that used nothing (total `0`). The
bill is created as soon as the month starts (the daily job, or the first time anyone reads the
project's billing) and is `open` while the month runs. Reading an open bill calculates it as of
now: usage up to the last metering run, and fixed resources projected to the end of the month — an
**estimate** (`"estimate": true`). A bill covers the days from `period_start` to `period_end`: its
month, except in a month in which billing was turned off and on again, which has a bill before and
one after the off period ([Turning billing off and on](#turning-billing-off-and-on)).

Every day at **02:30** (application time zone) the `billing-close` job closes every bill whose days
have ended — normally the bills of the month before. Closing calculates the final line items and
totals, stores them with the owner's name and e-mail, and records a `bill.closed` event. A closed
bill is left alone: a later price change, a renamed project or a new owner does not change it. A
project deleted during the month still gets its closed bill for that month. The job runs once at a
time (it holds a lock while it runs, and a second start returns), and each bill is closed on its
locked row: a bill closes, and its `bill.closed` event is recorded, exactly once.

Billing starts in an organization on the day it is first used there (`started_on` in
`GET /api/billing/settings`): months before that day get no bills, the month of that day is billed
in full from its metered usage. When the close job has not run for a while (an outage), its next
run closes the months that ended since, at most three months back.

A bill has **line items**. Each line names what it is for (metric, region, project), how much
(`quantity`, `unit`), the price (`unit_price`, `multiplier`, `markup_percent`) and the amount
(`total`). The bill's `total` is the sum of its line totals. When a price changes in the middle of
the month, the usage before and after the change are separate lines.

Amounts are calculated exactly — decimal arithmetic on the stored prices and the metered quantities,
never floating point — and each line is rounded once, half up, to the minor unit of the currency: 2
decimals, none for currencies without a minor unit (`JPY`, `KRW`, `CLP`, …), 3 for `BHD`, `IQD`,
`JOD`, `KWD`, `LYD`, `OMR` and `TND`. `fixed_total`, `usage_total`, `total` and `metered_cost` are
sums of rounded lines. A line shows `unit_price` with 6 decimals (fixed) or 8 (usage) and
`quantity` with 4; its `total` is calculated from the exact values.

### Account bills

Some resources belong to no project: [bindings](bindings.md) (data services that projects attach
to), [sandboxes](sandbox-api.md) and [model services](huggingface.md). Their usage is billed on an
**account bill** of the user who created them: one account bill per user and month, made when the
user has such usage in the month. Usage whose creator is unknown or deleted lands on an account
bill without owner ("Unassigned") — it is always priced and billed, never dropped. These resources
are always billed by usage (`payg` prices of their region), also in a fixed region.

### Rollup per owner

`GET /api/billing/rollup?period=2026-10` adds up every bill of a month per owner: how many projects
and bills, the total, and how much of it is paid and unpaid. Each user sees their own bills in
Settings → My bills (`GET /api/billing/me`).

### Payment link and paid status

Two things on a **closed** bill are written by your invoicing system, never by RunR:

| Field | Set with | Meaning |
|---|---|---|
| `payment_url` | `PUT /api/billing/bills/{bill}/payment-url` | where the payer pays (your invoice or checkout page); the console shows it as a **Pay** link on the bill. `null` clears it. |
| `paid`, `paid_at`, `payment_reference` | `PUT /api/billing/bills/{bill}/paid` | the bill is paid (or not any more), when, and your reference (invoice or payment number). |

RunR shows these values and sends events when they change (`bill.payment_url_set`, `bill.paid`,
`bill.unpaid`). It never stops, limits or warns a project because of an unpaid bill; stopping a
project is a separate, manual action ([Project stop and resume](#project-stop-and-resume)).

---

## Prices

### Price fields

A price version is complete: every field has a value (`0` is allowed and means "free" — a missing
price is never treated as zero).

| Field | Unit | Billed for | Default |
|---|---|---|---|
| `cpu_vcpu_hour` | per vCPU-hour | pay-as-you-go CPU of projects, model services and sandboxes | 0.04 |
| `memory_gb_hour` | per GB-hour | pay-as-you-go memory | 0.005 |
| `gpu_hour` | per GPU-hour | pay-as-you-go GPU time | 1.20 |
| `cpu_vcpu_month` | per vCPU-month | fixed resources: CPU | 25.00 |
| `memory_gb_month` | per GB-month | fixed resources: memory | 3.00 |
| `gpu_month` | per GPU-month | fixed resources: GPUs | 700.00 |
| `kv_gb_hour` | per GB-hour | KV binding storage | 0.0002 |
| `object_gb_hour` | per GB-hour | object storage binding | 0.00004 |
| `postgres_gb_hour` | per GB-hour | Postgres binding storage | 0.0002 |
| `postgres_instance_hour` | per instance-hour | dedicated Postgres cluster instances | 0.05 |
| `multiplier_http`, `multiplier_queue`, `multiplier_schedule` | factor | pay-as-you-go project usage by trigger type (sandboxes and bindings: 1) | 1 |

The defaults are seeded as the platform's default prices when billing starts for the first time
and remain the last fallback in code. They are starting values — set your own.

All amounts are in the installation's currency (`BILLING_CURRENCY`, default `USD`); there is no
currency conversion.

### Which price applies

RunR resolves the price of a region for every day separately and always finds one:

| Where | Order |
|---|---|
| Single-tenant installation, or the platform of a multi-tenant one | the region's own price version → the default prices → the built-in defaults |
| A tenant, for one of its own regions | the tenant's price for the region → the tenant's default prices → the platform's default prices → the built-in defaults |
| A tenant, for a platform region shared with it | the platform's price for the region → the platform's default prices → the built-in defaults, **× (1 + the tenant's markup %)** |
| The platform's bill to a tenant | the platform's price for the region → the platform's default prices → the built-in defaults (no markup) |

A tenant may treat one of its own regions as free by setting its prices to `0`; the region's
projects still get their bills (with total `0`).

### Effective dates

Prices never change in place. A change is a **new price version** with the date it takes effect
(`effective_from`, today or later). The version in effect on a day is the one with the latest
`effective_from` that is not after that day; the old prices hold until the new date. A version
that has not taken effect yet can be deleted; one that is in effect cannot (it is history). A new
version for a scope and date that already has one replaces it: there is only ever one version per
region (or the default prices) and date, also when two requests arrive at once.

```bash
# eu-1 costs more from 1 December; November is billed with the current prices
curl -sS -X POST "$RUNR/api/billing/prices" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{
  "region_id": "01JC0REGIONEU1XXXXXXXXXXXX", "effective_from": "2026-12-01",
  "cpu_vcpu_hour": 0.05, "memory_gb_hour": 0.006, "gpu_hour": 1.5,
  "cpu_vcpu_month": 30, "memory_gb_month": 3.5, "gpu_month": 800,
  "kv_gb_hour": 0.0002, "object_gb_hour": 0.00004, "postgres_gb_hour": 0.0002, "postgres_instance_hour": 0.05,
  "multiplier_http": 1, "multiplier_queue": 1, "multiplier_schedule": 1}'
```

`region_id: null` changes the default prices. A tenant's [markup](#markup) works the same way: a
new percentage with an effective date.

---

## Region billing types

Every region has exactly **one** billing type, chosen when the region is opened (the region wizard
asks for it when billing is on; Settings → Billing → Regions & prices, the region's Billing tab, or
`PUT /api/billing/regions/{region}` later). A region without a choice is `payg`. When the platform
shares a region with a tenant, the region keeps its type: the tenant sells it to its projects the
same way.

| Type | How projects get resources | What the project's bill contains |
|---|---|---|
| `payg` | Projects deploy at once, within the region's capacity and their organization's quota. | The metered usage of the month: vCPU-hours, GB-hours and GPU-hours of the project's workloads, by trigger type, at the region's hourly prices. |
| `fixed` | A project **requests** resources (vCPU, memory, GPUs); the region's seller approves; the project is active and billed from the approval day. It cannot use more than it was approved for: its largest size (every pod up to `max_scale`, its previews) must fit ([What a fixed project may use](#what-a-fixed-project-may-use)). | The monthly price of the approved resources, prorated by day. |

The type of a region cannot change while projects hold approved fixed resources there: the request
answers `409` with `code: active_fixed_allocations` and the number of such projects. End or move
those projects first.

Two choices of this design are made in one place each, `Modules\Billing\Support\BillingPolicy`, so
they can be changed later without touching the rest:

| Constant | Today |
|---|---|
| `TYPE_CHANGE_BLOCKED_BY_ACTIVE_ALLOCATIONS` | one billing type per region; a type change is refused while fixed resources are approved there |
| `FIXED_HARD_LIMIT` | a fixed project is limited to its approved resources at its worst case (`max_scale`, previews, GPUs) at every deploy and change; a request smaller than what runs is refused; a change is a new request, approved again and prorated by day from its approval |

---

## Fixed resources

### Requesting

The owner of a project in a fixed region (or anyone with `projects.billing` on it) requests
resources on the project's **Billing** tab or with
`POST /api/projects/{project}/billing/fixed-requests`:

```json
{"cpu_m": 2000, "memory_mb": 4096, "gpu_count": 0, "note": "launch in November"}
```

`cpu_m` is in millicores (2000 = 2 vCPU), `memory_mb` in MiB (4096 = 4 GB); GPUs only in a region
with GPU support. Before submitting, the console shows the **monthly price** — the region's monthly
prices × the amounts, with the tenant's markup on a shared region — and what the rest of the current
month costs from today (`GET /api/projects/{project}/billing/quote`): exactly what the bill charges
for an approval today, calculated the same way. There are no packages: whatever is requested is
priced from the unit prices. A new request replaces an older pending one of the same project (that
one is `cancelled`).

A request must cover what the project runs now: when the worst case of its live deployment and
previews ([What a fixed project may use](#what-a-fixed-project-may-use)) is larger than the
request, the request answers `422` with `code: scale_down_first` and the running worst case
(`running: {cpu_m, memory_mb, gpu_count}`). Scale the project down first — a lower `max_scale` or
size, deployed — then request the smaller resources. A project that runs a Knative Service without
`max_scale` (deployed before the region became fixed) has no worst case to compare: the request
answers `422 fixed_max_scale_required` until `max_scale` is set and the project redeployed.

### Approving

The **seller** of the region approves or rejects (Settings → Billing → Approvals,
`POST /api/billing/fixed-requests/{id}/approve` or `/reject` with a reason):

| Project | Seller (holds `billing.approve`) |
|---|---|
| on a single-tenant installation | an administrator of the installation |
| of a tenant, in the tenant's own or a shared platform region | an administrator of the tenant — the tenant sells shared regions to its users |
| of the platform (multi-tenant, no tenant) | a platform administrator |

Approvers get an in-app notification and an e-mail when a request comes in; the requester and the
owner get one when it is decided. The approval starts the **allocation**: `active_from` is the
approval day.

An approval is checked again when it is given: it is refused with `409 region_not_fixed` when the
region is no longer a fixed region, `409 project_moved` when the project left the region, and
`422 scale_down_first` when the project runs more than the request by now (`422
fixed_max_scale_required` when it runs without `max_scale`). When a region is switched
to pay as you go its pending requests are cancelled — on a shared platform region also the pending
requests of every tenant.

### What a fixed project may use

In a fixed region the allocation is a real limit. A fixed project deploys only with an approved
allocation, and everything it can run there must fit it **at its worst case**:

| Counted | How |
|---|---|
| The production workload | every pod up to `max(1, min_scale, max_scale)` — the size with the queue-proxy and sandbox (Kata) overheads, as the region's capacity admission counts them; a schedule trigger runs one pod, a queue trigger adds its consumer. A deploy's overrides (`deploy_config`: `cpu`, `memory_mb`, `min_scale`, `max_scale`, `scale_to_zero`) are part of it |
| Its preview environments | every live preview at its maximum (the project's size, at most 3 pods or `max_scale` when lower) |
| GPUs | the GPUs of one pod × the pods above |

A Knative Service (HTTP, queue, workflow trigger) needs a `max_scale` there — without one its worst
case is unbounded (`422 fixed_max_scale_required`).

It is checked at every way a project gets to run or grow: a deploy from the console, the CLI, the
API or a git push, a preview, a [resume](#project-stop-and-resume), a region migration, a runtime
test deploy, and a project update or `runr.yaml` change of its size or scaling. Without an
allocation the answer is `422` with `code: fixed_allocation_required`; above it — CPU, memory or
GPUs — `422` with `code: fixed_allocation_exceeded` and a `message` that says what the worst case
needs and what is approved:

```json
{"message": "At its maximum scale (5 pods, previews included) the project needs 1750 m CPU and 1140 MB memory; its approved resources are 1000 m CPU and 1024 MB. Lower max_scale or the size, or request more resources in the Billing tab.", "code": "fixed_allocation_exceeded"}
```

A project update carries the same `code` and `message` and the validation error on `config`; a
resume answers `200` without a deployment and the refusal in `redeploy_error` / `redeploy_code`; a
git push reports the message; a refused preview shows it as its status. Since the project cannot
exceed its allocation, there is no overage: its usage
is covered by the fixed price. Bindings, sandboxes and model services in the region are not part of
the allocation; they are billed by usage on the owner's account bill.

A **change** — more or fewer resources — is a new request. When it is approved, the old allocation
ends the day before (`superseded`) and the new one starts on the approval day. Deleting the project,
or moving it to another region, ends its allocation (`ended`) on that day — also while billing is
off. An allocation whose project is gone or in another region without that (removed directly in the
database, for instance) ends with the next run of `billing-close`, on the day before. Deleting a
tenant ends its allocations, and the platform's copies of them, on the day of the deletion.

### Proration

Fixed resources are billed per day: the monthly price ÷ the days of the month × the days the
allocation was active, the approval day included — calculated exactly and rounded once per line
(9.31 a month for 26 days of February: 8.645 → **8.65**). A month with a change has one line per
allocation:

| Line | Days | Daily | Total |
|---|---|---|---|
| Fixed resources 2 vCPU · 4 GB (eu-2), 10–19 Oct | 10 | 50.00 / 31 = 1.612903 | 16.13 |
| Fixed resources 4 vCPU · 8 GB (eu-2), 20–31 Oct | 12 | 100.00 / 31 = 3.225806 | 38.71 |

Price changes and markup changes during an allocation are applied from their effective date.

---

## Metering and the fixed bill

[Metering](monitoring.md#metering) runs whether billing is on or not. Every 5 minutes it records
each project's usage per day: CPU (millicore × milliseconds), memory (MiB × milliseconds), GPU time,
sessions; always-on workloads count their reserved size for the time they were running that day,
with the size they were deployed with (deployed before 0.5.8: the size their 0.5.7 record of the
day shows, [Upgrade](upgrade.md#057--058)). Bindings are measured
hourly (GB-hours of storage, instance hours), sandboxes every 5 minutes. Billing turns these
quantities into money: 1 vCPU-hour = 3.6 × 10⁹ millicore-milliseconds, 1 GB-hour = 1024 × 3.6 × 10⁶
MiB-milliseconds, 1 GPU-hour = 3.6 × 10⁶ GPU-milliseconds.

Fixed projects are metered too. The project's owner always sees the fixed bill. Users with
`billing.view` see on every project bill also:

| Field | Meaning |
|---|---|
| `metered_cost` | what the project's whole usage of the month would cost at the region's pay-as-you-go prices |
| `difference` | `total − metered_cost`: positive when the fixed price earns more than the usage would |
| `metered_lines` | the usage lines covered by the allocation (not billed) |

On a day with an active allocation, the project's own usage in that region is covered by the fixed
price and appears only in `metered_lines`. It cannot go above the allocation
([What a fixed project may use](#what-a-fixed-project-may-use)), so a fixed bill has no overage.

---

## Billing without tenancy

A single-tenant installation is one organization: the administrator opens regions, sets each
region's billing type and prices, and every project — the administrator's own included — is billed.
Everything lives in the central database.

**Example.** Two regions: `eu-1` (`payg`, vCPU-hour 0.04, GB-hour 0.005, HTTP multiplier 1) and
`eu-2` (`fixed`, vCPU-month 20.00, GB-month 2.50; hourly prices as `eu-1`). Ada owns two projects.

1. `orders-api` runs in `eu-1`, scale-to-zero. In October it is metered at 150 vCPU-hours and
   300 GB-hours:

   | Line | Quantity | Unit price | Total |
   |---|---|---|---|
   | vCPU hours (eu-1, HTTP) | 150 | 0.04 | 6.00 |
   | GB hours (eu-1, HTTP) | 300 | 0.005 | 1.50 |
   | **Bill** | | | **7.50** |

2. `ledger` is in `eu-2`. On 10 October Ada requests 2 vCPU / 4 GB: the console shows 50.00 per month
   (2 × 20.00 + 4 × 2.50) and 35.48 for the rest of October. An administrator approves the same day.
   On 20 October Ada asks for 4 vCPU / 8 GB (100.00 per month), approved that day. The October bill
   has the two [proration lines](#proration): 16.13 + 38.71 = **54.84**. Metered usage of `ledger`
   was 300 vCPU-hours and 600 GB-hours: `metered_cost` = 300 × 0.04 + 600 × 0.005 = 15.00,
   `difference` = 39.84 (shown to administrators only).

3. On 1 November at 02:30 both bills close; your invoicing system gets two `bill.closed` events. The
   rollup for Ada: 7.50 + 54.84 = **62.34**. The system invoices Ada, sets the payment link on both
   bills and marks them paid when the money arrives.

---

## Billing with tenancy

A multi-tenant installation has two levels of billing:

| Who bills | Whom | For | Prices | Stored in |
|---|---|---|---|---|
| the platform | each tenant (**tenant bill**, one per tenant and month) | the use of platform regions shared with the tenant, line items per project and region | the platform's prices | central database |
| the platform | its own projects (projects without tenant) | everything they use | the platform's prices | central database |
| each tenant | its projects and users (project and account bills) | its own regions at its own prices; shared platform regions at the platform price × (1 + markup) | tenant prices / platform prices + markup | the tenant's schema |

The tenant buys nothing up front: its users request resources in a shared region, and the tenant pays
the platform for what its projects use (payg) or hold (fixed allocations, at the platform's monthly
prices). The usage the platform bills for is copied to the central database by the metering job, so
the platform's bills never depend on reading a tenant's schema.

### Sharing a region: offer and acceptance

1. A platform administrator assigns a platform region to a tenant (Settings → Tenants → the tenant
   → Regions, `POST /api/tenants/{tenant}/regions`). With billing on, the assignment is an **offer**:
   its status is `pending`.
2. The tenant's administrators (members whose role grants `billing.manage`) get an in-app
   notification and an e-mail (notification type `billing`; each user can switch either channel
   off with `PUT /api/account/notification-preferences`).
3. The tenant administrator opens Settings → Billing → Region offers: the region, its billing type,
   the platform's prices (current and scheduled), and a markup field prefilled with the tenant's
   **default markup**. They may change it and **accept**
   (`POST /api/billing/region-offers/{region}/accept`).
4. Only then is the region active for the tenant: its users see it and can create projects in it.
   Until then the region is not usable by the tenant's users (it is not copied into the tenant).
   The markup and the acceptance are written in one step: an acceptance that fails leaves the
   offer pending, without a markup and without `region_share.accepted`, and can be repeated.

The platform's webhook receives `region_share.offered` and `region_share.accepted`. A region the
tenant chooses in the self-service onboarding wizard counts as accepted. Assignments made before
billing was switched on stay active.

Acceptance is only asked for while billing is on. With billing off, an assignment is active at once,
and offers that were still pending become active within five minutes (`tenant-region-shares-activate`):
the tenant's users can use the region. When billing is switched on again these regions stay active;
the tenant bills them with its default markup until it sets a markup for the region.

### Markup

A tenant has a **default markup** (prefilled on new offers) and a markup per accepted shared region.
Both are versions with an effective date, exactly like prices: a change takes effect on its date and
the old percentage holds until then (`POST /api/billing/markups`). The markup applies to every line of
the tenant's bills for that region — usage, fixed resources, bindings, sandboxes.

### Example

The platform shares `eu-1` (`payg`, vCPU-hour 0.04, GB-hour 0.005) and `eu-3` (`fixed`, vCPU-month
20.00, GB-month 2.50) with the tenant Acme. Acme's default markup is 20 %. It accepts `eu-1` with the
prefilled 20 % and `eu-3` with 10 %. Acme also has its own region `acme-dc` (`payg`, its own prices:
vCPU-hour 0.03, GB-hour 0.004).

| Acme project | October | Acme's bill to the project | Platform's bill to Acme |
|---|---|---|---|
| `shop` in `eu-1` | 100 vCPU-hours, 200 GB-hours | (4.00 + 1.00) × 1.20 = **6.00** | 4.00 + 1.00 = **5.00** |
| `db` in `eu-3` | 2 vCPU / 4 GB all month | 50.00 × 1.10 = **55.00** | **50.00** |
| `intranet` in `acme-dc` | 200 vCPU-hours, 100 GB-hours | 6.00 + 0.40 = **6.40** | — (Acme's own region) |

On 1 November the platform closes Acme's tenant bill: **55.00**, two lines per project and region.
Acme's projects' bills total 67.40. The fixed request of `db` was approved by an Acme administrator,
not by the platform. If Acme sets the `eu-1` markup to 25 % effective 15 November, November's
`shop` bill has the usage up to 14 November at 20 % and from 15 November at 25 % as separate lines.

Each side reads its own bills: the platform's invoicing system uses
`GET /api/billing/tenant-bills` (a platform administrator's token); Acme's administrators see the
bills from the platform in Settings → Billing → Platform bills (`GET /api/billing/platform-bills`) and
use their own invoicing system, with a token created inside the tenant, for their projects' bills.

The 0.5.7 charges the upgrade converted are closed tenant bills of their own (`source: legacy`):
they never stand in for the tenant's bill of the same month, which is made next to them.

### A converted installation

A single-organization installation converted into its first tenant (`php artisan tenancy:setup`)
bills every project in exactly one place from the conversion day on — the tenant. The central bills
of the conversion month end the day before and close with the next run of `billing-close`, the
fixed allocations move to the tenant, and the platform bills the tenant for its use of the platform
regions from the conversion day. The tenant gets no copy of the platform's webhook settings: set the
tenant's own webhook for its bills ([Billing after the conversion](tenancy.md#billing-after-the-conversion)).

### Bills of deleted tenants

A tenant's project and account bills live in its schema, and
[deleting the tenant](tenancy.md#tenant-lifecycle) drops that schema. Before it does, the deletion
job copies every one of those bills into the central table `billing_archived_bills`, with the
tenant's id, slug and name: period, project, payer (owner id, name, e-mail), line items and metered
lines, totals and metered cost, billing type, paid status, paid date, payment reference, payment
link and closing time. A closed bill is archived as it was closed. With billing on, every project
first gets its bill of the deletion month if it has none yet, as the daily job would make it. An
open bill (the month of the deletion, or an ended month the close job had not closed yet) is
archived with the estimate calculated at the deletion and stays `open`: it is never closed, and no
`bill.closed` event is sent for it.

If the bills cannot be copied, nothing is dropped: the tenant becomes `delete_failed` with
`bills not archived: …` in its status message, also with **Force delete**, and the deletion can be
retried. A retry replaces the tenant's archived bills, so each bill is in the archive once. The
platform's bills to the tenant, the central copies of its usage and fixed allocations, and the
platform's billing events are central and stay where they are. The tenant's own event log and
fixed requests go with its schema.

Platform administrators read the archive — read-only — with `GET /api/billing/archived-bills`
(`?tenant=` takes the deleted tenant's id or slug) and `GET /api/billing/archived-bills/{id}` (with
line items). An archived bill has the shape of [a bill](#the-bill), plus `bill_id` (its id in the
tenant's schema), `tenant` (`id`, `slug`, `name`) and `archived_at`, always with `metered_cost`
and `difference` (and, for one bill, the metered lines). The table exists on every installation and
stays empty without tenancy.

---

## Project stop and resume

An administrator — inside a tenant, a tenant administrator — or a platform administrator can
**stop** a project and **resume** it later. This is part of RunR itself and works with billing off;
billing operators use it, for example when an owner does not pay. It needs the permission
`projects.stop`.

```bash
curl -sS -X POST "$RUNR/api/projects/$PROJECT/stop" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"reason": "October bill unpaid, see invoice INV-2026-0042"}'
curl -sS -X POST "$RUNR/api/projects/$PROJECT/resume" -H "Authorization: Bearer $TOKEN"
```

| | Stopped project |
|---|---|
| Workloads | the running deployment is shut down: its Knative Service, CronJob and queue consumer are removed, and its [preview environments](git-deploy.md) are deleted — nothing serves. A deploy that was queued before the stop does nothing when it runs, and one that was being applied at that moment removes what it applied |
| Deploys | refused until it is resumed: `409` with `code: project_stopped`, the reason and the `level` — console, CLI, git pushes, previews (new pull requests and redeploys), region migrations |
| Level | `platform` when a platform administrator stopped it, else `organization` (an administrator of the installation or the tenant). Only the same level or a higher one resumes: an organization cannot lift the platform's stop (`403`, `code: project_stopped_by_platform`). A platform administrator stopping a project its organization stopped takes the stop over (level `platform`, the new reason) |
| Members | see a banner with the reason, when, and who stopped it: "stopped by the platform" (the administrator is not named) or the organization's administrator; the owner gets a notification |
| Resume | deploys the version that was running when the project was stopped again, with that deployment's overrides, through every check of a deploy (the version must still be built, the region active, residency and supply-chain policies, a fixed region's [allocation](#what-a-fixed-project-may-use)), and answers with its `deployment_id`. When the redeploy is refused, the project is resumed without a deployment, `redeploy_error` says why and `redeploy_code` carries the code of a deploy guard (e.g. `fixed_allocation_exceeded`) — deploy a version that fits |
| Audit | `project.stop` (with the reason) and `project.resume` |
| Billing | `project.stopped` (with the `level`) / `project.resumed` events; a stopped project uses nothing, a fixed allocation stays billed until it is changed or ended |

The reason is required (3–1000 characters). Stopping a stopped project answers `409`
(`project_already_stopped`, with the `level` and `reason` of the stop) — also when two stops arrive
at the same moment: one of them stops the project, the other changes nothing. Resuming a running one
answers `409` (`project_not_stopped`).

In the console a platform administrator takes an organization's stop over on the **project page**:
on a project its organization stopped, the page header offers **Stop for the platform**, and its
dialog says that the stop becomes a platform stop — the new reason replaces the organization's,
the members see that the platform stopped the project, and only a platform administrator resumes
it. Settings → Billing → Stop projects resumes stopped projects but does not take a stop over.

---

## API reference

Base path `/api`, JSON, `Authorization: Bearer <token>`. Every billing route works on the
**organization of the request**:

| Request | Organization | Bills |
|---|---|---|
| single-tenant installation | the installation | every project and account bill |
| multi-tenant, no active tenant (platform administrators) | the platform | the platform's own project and account bills; tenant bills under `/billing/tenant-bills` |
| multi-tenant, inside a tenant | the tenant | the tenant's project and account bills; its bills from the platform under `/billing/platform-bills` |

### API tokens

Create a token for your invoicing system in Settings → API Tokens with only the permissions it
needs: `billing.view` to read, `billing.manage` to write payment links and paid status (and, if it
should approve fixed resources, `billing.approve`). A token belongs to the organization it was
created in: on a multi-tenant installation, a platform administrator creates the platform's token
with no tenant active; a tenant creates its own inside the tenant. The token reaches the billing
API and the user's own account, nothing else ([API tokens](permissions.md#api-tokens)).

```bash
curl -sS -X POST "$RUNR/api/tokens" -H "Authorization: Bearer $ADMIN_SESSION_TOKEN" \
  -H 'Content-Type: application/json' -d '{"name": "invoicing", "abilities": ["billing.view", "billing.manage"]}'
```

### Endpoints

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | `/billing/bills` | `billing.view` | bills: `?period=YYYY-MM` `?status=open\|closed` `?paid=true\|false` `?owner=<user id>` `?project=<id>` `?kind=project\|account` `?per_page=` (≤ 200, default 50); open bills with their estimate |
| GET | `/billing/bills/{bill}` | `billing.view` | one bill with its line items |
| PUT | `/billing/bills/{bill}/payment-url` | `billing.manage` | `{"payment_url": "https://…" \| null}` — closed bills only |
| PUT | `/billing/bills/{bill}/paid` | `billing.manage` | `{"paid": true\|false, "paid_at"?: ISO 8601 (default now), "payment_reference"?: "…"}` — closed bills only |
| GET | `/billing/usage` | `billing.view` | metered usage per project: `?period=YYYY-MM` (default this month) `?project=<id>` |
| GET | `/billing/rollup` | `billing.view` | totals per owner: `?period=YYYY-MM` |
| GET | `/billing/me` | signed in | the signed-in user's own bills: `?period=YYYY-MM` |
| GET | `/billing/fixed-requests` | `billing.view` | fixed resource requests: `?status=pending\|approved\|rejected\|cancelled\|superseded\|ended` `?project=` `?region=` `?per_page=` |
| POST | `/billing/fixed-requests/{id}/approve` | `billing.approve` | `{"note"?}` — starts the allocation today |
| POST | `/billing/fixed-requests/{id}/reject` | `billing.approve` | `{"reason": "…"}` (required) |
| GET | `/billing/regions` | `billing.view` | regions with billing type, current and scheduled prices, active allocations, pending requests |
| PUT | `/billing/regions/{region}` | `billing.manage` | `{"billing_type": "payg"\|"fixed"}` |
| GET | `/billing/prices` | `billing.view` | the default prices (current, scheduled, history) and, in a tenant, the platform defaults it falls back to |
| POST | `/billing/prices` | `billing.manage` | a new price version: `{"region_id": null\|"<id>", "effective_from": "YYYY-MM-DD", …every price field}` |
| DELETE | `/billing/prices/{price}` | `billing.manage` | a version that has not taken effect yet |
| GET | `/billing/markups` | `billing.view` | tenant: default markup and markup per shared region (current, scheduled, history) |
| POST | `/billing/markups` | `billing.manage` | tenant: `{"region_id": null\|"<shared region>", "percent": 0–1000, "effective_from": "YYYY-MM-DD"}` |
| DELETE | `/billing/markups/{markup}` | `billing.manage` | tenant: a markup version that has not taken effect yet |
| GET | `/billing/region-offers` | `billing.view` | tenant: shared platform regions (pending and accepted) with type, platform prices and markup |
| POST | `/billing/region-offers/{region}/accept` | `billing.manage` | tenant: `{"markup_percent"?: 0–1000}` (default: the default markup) |
| GET | `/billing/platform-bills` | `billing.view` | tenant: the tenant's bills from the platform: `?period=` `?status=` `?paid=` |
| GET | `/billing/platform-bills/{bill}` | `billing.view` | tenant: one of them with line items |
| GET | `/billing/tenant-bills` | `@platform:billing.view` | platform: every tenant bill: `?tenant=<id or slug>` `?period=` `?status=` `?paid=` `?per_page=` |
| GET | `/billing/tenant-bills/{bill}` | `@platform:billing.view` | platform: one tenant bill with line items |
| PUT | `/billing/tenant-bills/{bill}/payment-url` | `@platform:billing.manage` | as for project bills |
| PUT | `/billing/tenant-bills/{bill}/paid` | `@platform:billing.manage` | as for project bills |
| GET | `/billing/legacy-records` | `@platform:billing.view` | the 0.5.7 billing records kept by the upgrade: `?source=charges\|plans\|tenant_subscriptions\|billing_purchases\|capacity_deals\|cost_configs` `?tenant=` `?per_page=` |
| GET | `/billing/archived-bills` | `@platform:billing.view` | platform: the project and account bills of deleted tenants ([Bills of deleted tenants](#bills-of-deleted-tenants)): `?tenant=<id or slug>` `?period=` `?status=open\|closed` `?paid=` `?kind=project\|account` `?per_page=` |
| GET | `/billing/archived-bills/{id}` | `@platform:billing.view` | platform: one of them with line items and metered lines |
| GET | `/billing/settings` | `billing.view` | currency, organization, webhook (URL, where it comes from, whether a secret is set), `started_on`, `warnings` (install / platform: e.g. `legacy_currency_mismatch`, [Upgrade from 0.5.7](#upgrade-from-057)); in a tenant the default markup |
| PUT | `/billing/settings` | `billing.manage` | `{"webhook_url"?: "https://…"\|null, "webhook_secret"?: "…" (≥ 16 characters), "clear_secrets"?: ["webhook_secret"]}` — a URL that is not [allowed](#webhook-events) answers `422` |
| GET | `/billing/events` | `billing.view` | the event log: `?after=<id>` `?before=<id>` `?order=asc\|desc` `?type=` `?limit=` (≤ 500) |
| POST | `/billing/events/test` | `billing.manage` | records and delivers a `billing.test` event |
| POST | `/billing/events/{event}/redeliver` | `billing.manage` | delivers an event again |
| GET | `/projects/{project}/billing` | owner, `projects.billing` or `billing.view` | the project's billing tab: type, region, allocation, pending request, current bill, closed bills |
| GET | `/projects/{project}/billing/quote` | same | monthly price of `?cpu_m=&memory_mb=&gpu_count=` and the prorated rest of this month |
| GET | `/projects/{project}/billing/bills/{bill}` | same | one bill of the project |
| POST | `/projects/{project}/billing/fixed-requests` | same | `{"cpu_m", "memory_mb", "gpu_count"?, "note"?}` |
| POST | `/projects/{project}/billing/fixed-requests/{id}/cancel` | same | a pending request |
| PUT | `/projects/{project}/owner` | the owner or `projects.manage_access` | `{"user_id": "…"}` — transfer the project |
| POST | `/projects/{project}/stop` | `projects.stop` | `{"reason": "…"}` |
| POST | `/projects/{project}/resume` | `projects.stop` | resume |

Lists answer `{"data": [...], "meta": {"current_page", "last_page", "per_page", "total"}}`. Errors:
`401` no or invalid token, `403` with the permission that is needed (`{"permissions": ["billing.manage"]}`),
`404` unknown or not in this organization, `409` with a `code`, `422` validation errors (with a
`code` where noted).

| `code` | Status | When |
|---|---|---|
| `bill_open` | 409 | a payment link or paid status on a bill that is still open |
| `price_in_effect`, `markup_in_effect` | 409 | deleting a version that has taken effect |
| `active_fixed_allocations` | 409 | changing the type of a region with approved fixed resources |
| `region_type_owned_by_platform`, `region_prices_owned_by_platform` | 422 | a tenant changing the type or prices of a shared platform region |
| `tenant_context_required` | 404 | markups, region offers or platform bills outside a tenant |
| `offer_not_pending` | 409, 404 | accepting an offer that is already accepted (409) or does not exist (404) |
| `request_not_pending` | 409 | approving, rejecting or cancelling a decided request |
| `project_moved` | 409 | approving a request after the project moved to another region |
| `region_not_fixed`, `gpu_not_available` | 422 | a fixed request in a `payg` region, GPUs where the region has none |
| `region_not_fixed` | 409 | approving a request in a region that is not fixed any more |
| `scale_down_first` | 422 | a fixed request (or its approval) smaller than the worst case the project runs now |
| `fixed_allocation_required`, `fixed_allocation_exceeded`, `fixed_max_scale_required` | 422 | a deploy, preview redeploy, runtime test deploy or size change of a fixed project without / above its allocation, or without a `max_scale`; `fixed_max_scale_required` also for a fixed request (or its approval) while the project runs without one |
| `project_stopped` | 409 | a deploy of a stopped project |
| `project_already_stopped`, `project_not_stopped` | 409 | stop / resume in the wrong state |
| `project_stopped_by_platform` | 403 | an organization resuming a project the platform stopped |
| `webhook_not_configured` | 409 | test event or redelivery without a webhook |

### The bill

```json
{
  "id": "01JD2K8Q4W7ZK3M5N6P8R9S0TA",
  "kind": "project",
  "period": "2026-10",
  "period_start": "2026-10-01",
  "period_end": "2026-10-31",
  "status": "closed",
  "estimate": false,
  "currency": "EUR",
  "source": "runr",
  "project": {"id": "01JA9F…", "name": "ledger"},
  "owner": {"id": "7", "name": "Ada Example", "email": "ada@example.com"},
  "tenant": null,
  "billing_type": "fixed",
  "line_items": [
    {
      "type": "fixed", "metric": "fixed_resources",
      "description": "Fixed resources 2 vCPU · 4 GB (eu-2), 10–19 Oct",
      "region_id": "01JC0…", "region_name": "eu-2", "project_id": "01JA9F…", "project_name": "ledger",
      "quantity": 10, "unit": "days", "unit_price": 1.612903, "multiplier": 1, "markup_percent": 0,
      "total": 16.13, "from": "2026-10-10", "to": "2026-10-19", "price_id": "01JB…",
      "metadata": {"fixed_request_id": "01JD1…", "cpu_m": 2000, "memory_mb": 4096, "gpu_count": 0}
    },
    {
      "type": "fixed", "metric": "fixed_resources",
      "description": "Fixed resources 4 vCPU · 8 GB (eu-2), 20–31 Oct",
      "region_id": "01JC0…", "region_name": "eu-2", "project_id": "01JA9F…", "project_name": "ledger",
      "quantity": 12, "unit": "days", "unit_price": 3.225806, "multiplier": 1, "markup_percent": 0,
      "total": 38.71, "from": "2026-10-20", "to": "2026-10-31", "price_id": "01JB…",
      "metadata": {"fixed_request_id": "01JD3…", "cpu_m": 4000, "memory_mb": 8192, "gpu_count": 0}
    }
  ],
  "fixed_total": 54.84,
  "usage_total": 0,
  "total": 54.84,
  "metered_cost": 15.0,
  "difference": 39.84,
  "metered_lines": [ "… usage lines covered by the allocation …" ],
  "payment_url": "https://invoicing.example.com/pay/INV-2026-0042",
  "payment_url_set_at": "2026-11-01T08:12:40+00:00",
  "paid": true,
  "paid_at": "2026-11-04T10:00:00+00:00",
  "payment_reference": "INV-2026-0042",
  "closed_at": "2026-11-01T02:30:07+00:00",
  "created_at": "2026-10-01T02:30:01+00:00",
  "updated_at": "2026-11-04T10:00:00+00:00"
}
```

| Field | Meaning |
|---|---|
| `kind` | `project`, `account` (resources of a user outside projects) or `tenant` (the platform's bill to a tenant) |
| `period_start`, `period_end` | the days the bill covers: its month, or the part of it billing was on ([Turning billing off and on](#turning-billing-off-and-on)) or the converted tenant billed ([A converted installation](#a-converted-installation)) |
| `estimate` | `true` while the bill is open: the amounts are as of now (fixed resources to the end of the month) |
| `source` | `runr`, or `legacy` for a 0.5.7 charge converted by the upgrade |
| `billing_type` | `payg`, `fixed`, `mixed` (both in one month) or `null` (account and tenant bills) |
| `line_items` | in `GET /billing/bills/{bill}` and the project and tenant views; `null` in lists |
| `fixed_total`, `usage_total`, `total` | the fixed lines, the usage lines, both |
| `metered_cost`, `difference`, `metered_lines` | only for `billing.view` holders ([Metering and the fixed bill](#metering-and-the-fixed-bill)) |

Line item `type` is `usage`, `fixed` or `credit` (converted 0.5.7 bills); `metric` one of
`vcpu_hours`, `memory_gb_hours`, `gpu_hours`, `fixed_resources`, `binding_storage_gb_hours`,
`binding_instance_hours`, `sandbox_vcpu_hours`, `sandbox_memory_gb_hours`, `legacy`; `unit` one of
`vCPU-hours`, `GB-hours`, `GPU-hours`, `days`, `item`. Usage lines carry `entry_point` (trigger type)
for projects and `resource_type` / `resource_id` / `resource_name` for bindings, sandboxes and model
services. Tenant bills carry `project_id` / `project_name` on every line.

### A fixed resource request

```json
{
  "id": "01JD1…", "project": {"id": "01JA9F…", "name": "ledger"}, "region": {"id": "01JC0…", "name": "eu-2"},
  "cpu_m": 2000, "memory_mb": 4096, "gpu_count": 0, "vcpu": 2, "memory_gb": 4,
  "monthly_price": 50.0, "currency": "EUR", "status": "approved", "note": "launch in November",
  "requested_by": {"id": "7", "name": "Ada Example"}, "requested_at": "2026-10-10T09:14:02+00:00",
  "decided_by": {"id": "1", "name": "Admin"}, "decided_at": "2026-10-10T11:02:51+00:00", "decision_reason": null,
  "active_from": "2026-10-10", "active_until": "2026-10-19"
}
```

`status`: `pending` → `approved` | `rejected` | `cancelled`; an approved one becomes `superseded`
(replaced by a newer approval) or `ended` (project deleted or moved). `monthly_price` is the quote at
request time; the bill always uses the prices in effect on each day.

### Quote

`GET /api/projects/{project}/billing/quote?cpu_m=2000&memory_mb=4096&gpu_count=0`

```json
{
  "currency": "EUR", "monthly_price": 50.0,
  "breakdown": {"cpu": 40.0, "memory": 10.0, "gpu": 0.0},
  "markup_percent": 0,
  "prices": {"cpu_vcpu_month": 20.0, "memory_gb_month": 2.5, "gpu_month": 700.0},
  "first_month": {"from": "2026-10-10", "to": "2026-10-31", "days": 22, "amount": 35.48}
}
```

### Usage

`GET /api/billing/usage?period=2026-10&project=01JA9F…`

```json
{
  "period": "2026-10", "from": "2026-10-01", "to": "2026-10-31", "currency": "EUR",
  "data": [{
    "project": {"id": "01JA9F…", "name": "ledger"}, "owner": {"id": "7", "name": "Ada Example", "email": "ada@example.com"},
    "region_ids": ["01JC0…"],
    "quantities": {"vcpu_hours": 300.0, "memory_gb_hours": 600.0, "gpu_hours": 0.0, "sessions": 0},
    "cost": 15.0
  }]
}
```

`cost` is the pay-as-you-go cost of all the usage (the `metered_cost` of the bill).

This is where usage turns into money. The usage endpoints of the core —
`GET /api/bindings/{id}/usage`, `GET /api/sandbox-usage` — answer quantities only; what bindings
and sandboxes cost is on the account bills (`GET /api/billing/bills?kind=account`).

---

## Webhook events

Events are recorded in the organization's event log and, when a webhook URL is set, POSTed to it.
The platform (or the installation) and every tenant have their own log and their own webhook
(Settings → Billing → Webhook, `PUT /api/billing/settings`); the Helm / environment values
`BILLING_WEBHOOK_URL` and `BILLING_WEBHOOK_SECRET` are the default of the installation and the
platform only — a tenant never falls back to the platform's webhook.

**Allowed targets.** A webhook URL is checked when it is saved and again before every delivery, as
the [alert webhooks](observability.md#channels) are: `http://` or `https://`, no user name or password
in the URL, and no loopback, private, link-local, CGNAT, multicast or cluster-internal address —
neither the host itself nor any address its name resolves to; the delivery goes to an address that
was checked. A tenant's webhook is always held to this. The webhook the platform (or the
installation) saves in its settings may reach private addresses only with
`observability.alerts.allowPrivateTargets` ([Helm values](helm-values.md)); `BILLING_WEBHOOK_URL`
is the operator's own setting and may name an internal service. A URL that fails the check is
refused with `422` when saved; a delivery to it fails like an unreachable endpoint and is retried.
A tenant sees a failed delivery as "connection failed" or "did not answer in time", without the
addresses involved.

| Type | When | `data` |
|---|---|---|
| `bill.closed` | a bill closed after its month | `bill` (with line items) |
| `bill.paid` | a bill was marked paid | `bill` |
| `bill.unpaid` | the paid mark was removed | `bill` |
| `bill.payment_url_set` | a payment link was set or cleared | `bill` (`payment_url` may be `null`) |
| `fixed_request.created` | a project requested fixed resources | `fixed_request` |
| `fixed_request.approved` | a request was approved | `fixed_request` |
| `fixed_request.rejected` | a request was rejected | `fixed_request` |
| `region_share.offered` | the platform shared a region with a tenant (platform log) | `tenant`, `region`, `billing_type` |
| `region_share.accepted` | the tenant accepted it (platform log) | `tenant`, `region`, `billing_type`, `markup_percent` |
| `project.stopped` | a project was stopped (or the platform took an organization's stop over) | `project`, `owner_id`, `reason`, `stopped_by`, `level` (`platform` / `organization`) |
| `project.resumed` | a project was resumed | `project`, `deployment_id` |
| `project.owner_changed` | a project got a new owner | `project`, `previous_owner_id`, `owner_id` |
| `billing.test` | Send test event | `message` |

Every event has the same envelope — exactly the body the webhook receives:

```json
{
  "id": "01JD4Q9N8M2A6B4C7D5E1F0GH",
  "type": "bill.closed",
  "created_at": "2026-11-01T02:30:07+00:00",
  "organization": {"kind": "tenant", "id": "9b2f6a3e-5c1d-4c47-9a8e-0f6b2d7c1e44", "slug": "acme", "name": "Acme"},
  "data": {"bill": { "… the bill as in the API …" }}
}
```

`organization.kind` is `install` (single-tenant), `platform` or `tenant`; `id`, `slug` and `name` are
`null` for the installation and the platform.

**Delivery.**

```
POST /hooks/runr HTTP/1.1
Content-Type: application/json
User-Agent: RunR-Billing/1
X-RunR-Event: bill.closed
X-RunR-Delivery: 01JD4Q9N8M2A6B4C7D5E1F0GH
X-RunR-Timestamp: 1793500207
X-RunR-Signature: sha256=<hex HMAC-SHA256(webhook secret, "<X-RunR-Timestamp>.<raw body>")>
```

A `2xx` answer delivers the event. Anything else, a timeout (10 s) or a refused connection is
retried after 1, 5, 15, 60, 180, 360, 720 and 1 440 minutes, up to 10 attempts; then the event's
delivery status is `failed` and `POST /api/billing/events/{event}/redeliver` queues it again. A failed
delivery holds back the later events of the same log until the next attempt (about a minute later),
so events arrive in order. Delivery is at least once: deduplicate by `id` (also `X-RunR-Delivery`).
Redirects are not followed. Without a secret the `X-RunR-Signature` header is left out.

Verify the signature over the raw body, and the timestamp against your clock:

```python
import hashlib, hmac, time

def verify(secret: bytes, timestamp: str, raw_body: bytes, signature: str, tolerance: int = 300) -> bool:
    if abs(time.time() - int(timestamp)) > tolerance:
        return False
    expected = "sha256=" + hmac.new(secret, timestamp.encode() + b"." + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)
```

```js
const crypto = require('node:crypto')

function verify(secret, timestamp, rawBody, signature, tolerance = 300) {
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > tolerance) return false
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(`${timestamp}.`).update(rawBody).digest('hex')
  return expected.length === signature.length && crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature))
}
```

**Event log.** Events are kept for 90 days (`BILLING_EVENTS_RETENTION_DAYS`), with or without a
webhook. `GET /api/billing/events?after=<id>` returns the events after the last one you processed —
read on with `?after=<meta.next_after>` until it is `null`; `?order=desc&limit=20` the 20 most recent.
Each event carries its delivery status, attempts and last error. A webhook outage is caught up by
reading the log.

---

## Example: invoicing a closed month

Your invoicing system holds a token with `billing.view` and `billing.manage` and the webhook secret.

1. **The month ends.** On 1 November at 02:30 RunR closes October. Your webhook receives one
   `bill.closed` event per bill. Verify the signature and store the event id (to ignore a redelivery).

2. **Read what is owed.** The event carries the bill. To work from the API instead — a nightly job,
   or catching up after downtime:

   ```bash
   curl -sS "$RUNR/api/billing/bills?period=2026-10&status=closed&paid=false&per_page=200" \
     -H "Authorization: Bearer $TOKEN"
   curl -sS "$RUNR/api/billing/rollup?period=2026-10" -H "Authorization: Bearer $TOKEN"
   ```

   Invoice each owner — one invoice per bill, or one per owner from the rollup — with the line items
   as invoice lines. Bills with total `0` need no invoice.

3. **Set the payment link** of each invoiced bill, so the owner sees a Pay link in the console:

   ```bash
   curl -sS -X PUT "$RUNR/api/billing/bills/01JD2K8Q4W7ZK3M5N6P8R9S0TA/payment-url" -H "Authorization: Bearer $TOKEN" \
     -H 'Content-Type: application/json' -d '{"payment_url": "https://invoicing.example.com/pay/INV-2026-0042"}'
   ```

4. **Collect the payment** in your system. RunR is not involved.

5. **Mark the bill paid:**

   ```bash
   curl -sS -X PUT "$RUNR/api/billing/bills/01JD2K8Q4W7ZK3M5N6P8R9S0TA/paid" -H "Authorization: Bearer $TOKEN" \
     -H 'Content-Type: application/json' -d '{"paid": true, "paid_at": "2026-11-04T10:00:00Z", "payment_reference": "INV-2026-0042"}'
   ```

   The `?paid=false` filter of step 2 skips it from now on. If you decide to stop a project of an owner
   who does not pay, an administrator does so with a reason ([Project stop and resume](#project-stop-and-resume)) —
   RunR never does it by itself.

6. **Multi-tenant platform:** the same for tenant bills with `GET /api/billing/tenant-bills?period=2026-10`
   and `PUT /api/billing/tenant-bills/{bill}/payment-url` / `/paid` with a platform administrator's token.

---

## In the console

| Where | Who | What |
|---|---|---|
| Settings → Billing → Bills | `billing.view` | every bill of the organization with filters; fixed price, metered cost and the difference; payment link and paid status (`billing.manage`) |
| Settings → Billing → Approvals | `billing.approve` | pending fixed resource requests with their monthly price; approve, reject with a reason |
| Settings → Billing → Regions & prices | `billing.view`, edits `billing.manage` | billing type per region, default prices, price versions with effective dates |
| Settings → Billing → Owners | `billing.view` | the rollup per owner |
| Settings → Billing → Stop projects | `projects.stop` | stop and resume projects with a reason; who stopped each one (the platform or the organization) — a platform stop is resumed by a platform administrator only |
| Settings → Billing → Region offers | tenant, `billing.manage` | shared platform regions waiting for acceptance with type and platform prices; accept with markup; default markup with effective date |
| Settings → Billing → Platform bills (tenant) / Tenant bills (platform) | tenant `billing.view`; platform administrators | a tenant's bills from the platform; for platform administrators every tenant bill with payment link and paid status |
| Settings → Billing → Webhook | `billing.manage` | webhook URL and secret, test event, recent events, redelivery |
| Settings → My bills | every user | the bills the user owns, with payment links and paid status |
| Project → Billing | the owner, `projects.billing`, `billing.view` | this month's estimate, past bills with payment link and paid status, fixed resource request with the monthly price before submitting, the active allocation |
| Region wizard, region → Billing | `billing.manage` | the billing type of a new region; the type and prices of a region |
| Project page | every member | a banner when the project is stopped (reason, when, and "stopped by the platform" or the organization's administrator); Stop / Resume for `projects.stop` (Resume of a platform stop for platform administrators; on a project its organization stopped, platform administrators get **Stop for the platform**, which [takes the stop over](#project-stop-and-resume)); owner and transfer |

---

## Permissions

| Permission | Scope | Grants |
|---|---|---|
| `billing.view` | organization | bills, usage, rollup, prices, region billing types, fixed requests, events, the webhook settings (without the secret); in a tenant also region offers, markups and the bills from the platform |
| `billing.manage` (includes `billing.view`) | organization | prices, region billing types, markups, accepting region offers, payment links and paid status, the webhook, test events and redelivery |
| `billing.approve` (includes `billing.view`) | organization | approve or reject fixed resource requests |
| `projects.billing` (includes `projects.view`) | project | the project's own bills and its fixed resource requests — the project's owner has this by ownership |
| `projects.stop` | organization | stop and resume projects |

The built-in **Billing** role (both editions) has `billing.view`, `billing.manage`, `billing.approve`,
`projects.stop` and read access to regions, bindings and members. Owner and Admin have every
permission. Platform administrators pass every billing permission in every organization; their API
tokens need the permission explicitly. The platform's tenant bills and the 0.5.7 records need a
platform administrator (`@platform:billing.view` / `@platform:billing.manage` in
`api/config/route_permissions.php`) — a tenant's own `billing.*` permissions never reach them. See
[Roles and permissions](permissions.md#billing).

Every write is in the [audit log](security.md#audited-events): `billing.settings.update`,
`billing.price.create` / `.delete`, `billing.region.update`, `billing.markup.create` / `.delete`,
`billing.region_offer.accept`, `billing.bill.payment_url.update`, `billing.bill.paid.update`,
`billing.tenant_bill.payment_url.update`, `billing.tenant_bill.paid.update`,
`billing.fixed_request.create` / `.cancel` / `.approve` / `.reject`, `billing.event.test`,
`billing.event.redeliver`, `project.owner.transfer`, `project.stop`, `project.resume`.

---

## Configuration reference

| Env | Helm value | Default | Meaning |
|---|---|---|---|
| `BILLING_ENABLED` | `billing.enabled` | `false` | the Billing module on |
| `BILLING_CURRENCY` | `billing.currency` | `USD` | ISO 4217 currency of every price and bill (after an upgrade from 0.5.7: the currency of its cost configuration). A change relabels the open bills; closed bills keep theirs |
| `BILLING_WEBHOOK_URL` | `billing.webhookUrl` | empty | default webhook of the installation / platform; empty: event log only |
| `BILLING_WEBHOOK_SECRET` | `billing.webhookSecret` (Secret `runr-secret`) | empty | HMAC-SHA256 key of `X-RunR-Signature` |
| `BILLING_WEBHOOK_TIMEOUT` | `extraEnv` | `10` | seconds per delivery attempt |
| `BILLING_WEBHOOK_MAX_ATTEMPTS` | `extraEnv` | `10` | attempts per event |
| `BILLING_EVENTS_RETENTION_DAYS` | `extraEnv` | `90` | days events are kept |
| `VITE_BILLING_ENABLED` | — (UI image build flag) | published images: `true` | the console contains the billing screens; they are shown only while the API reports billing on |

A webhook URL saved in Settings → Billing → Webhook wins over `BILLING_WEBHOOK_URL`; clearing it there
brings the environment value back. The secret is stored encrypted (`billing_settings`) and never
returned by the API — the settings show only whether one is set.

---

## Scheduled jobs and tables

| Job | When | What |
|---|---|---|
| `usage-aggregation` | every 5 minutes, always (also with billing off) | the day's usage of every project and model service into `usage_records` (central database and every tenant schema; the first hour of a day also the day before), copies of tenant usage in platform regions to the central database |
| `billing-close` | daily 02:30 | closes every bill whose days have ended (organization by organization, then the tenant bills), opens the current month's bills, ends allocations of projects that are gone; one run at a time |
| `billing-event-delivery` | every minute | webhook deliveries and retries |
| `billing-event-retention` | daily 03:10 | removes events older than the retention |
| `billing-heartbeat` | hourly, billing on | records the day as billed ([Turning billing off and on](#turning-billing-off-and-on)) |
| `billing-off-marker` | hourly, billing off | records the day as not billed (once billing ran on the installation) |
| `tenant-region-shares-activate` | every 5 minutes (Tenancy module) | with billing off: pending region offers become active |

Bindings are measured hourly and sandboxes every 5 minutes by their own jobs
([monitoring](monitoring.md#metering)).

| Table | Where | Content |
|---|---|---|
| `billing_prices` | central and tenant schemas | price versions per region and the default prices |
| `billing_region_settings` | central and tenant schemas | billing type per region |
| `billing_markups` | tenant schemas | default markup and markup per shared region (versions) |
| `billing_fixed_requests` | central and tenant schemas | fixed resource requests and allocations; central also copies of tenants' allocations in platform regions |
| `billing_bills` | central and tenant schemas | project and account bills; central also tenant bills |
| `billing_events` | central and tenant schemas | event log and delivery state |
| `billing_settings` | central and tenant schemas | webhook URL and encrypted secret of the organization, the day billing started (`started_on`; a converted tenant also `bills_from`); centrally the days billing was on and off (`active_on`, `off_seen_on`, `off_periods`) and the currencies of the imported 0.5.7 prices (`legacy_currencies`) |
| `billing_archived_bills` | central | the project and account bills of deleted tenants ([Bills of deleted tenants](#bills-of-deleted-tenants)) |
| `billing_legacy_archive` | central | the 0.5.7 billing records ([upgrade](upgrade.md#057--058)) |
| `usage_records`, `binding_usage_records` | central and tenant schemas | metered usage (core) |
| `sandbox_usage_records` | central | metered sandbox usage of every organization (core) |

---

## Upgrade from 0.5.7

0.5.7 had plans, subscriptions, credits, purchases, add-ons, capacity deals and charges per tenant.
They are gone. The upgrade keeps their data: every row is archived in `billing_legacy_archive`
(readable with `GET /api/billing/legacy-records`), the cost configuration becomes the platform's
price versions, closed charges become closed tenant bills, and the old tables are dropped. Each step
is in [Upgrading → 0.5.7 → 0.5.8](upgrade.md#057--058).

The imported prices are plain numbers: 0.5.8 reads them in `BILLING_CURRENCY`. Set it to the
currency of your 0.5.7 cost configuration in the upgrade to 0.5.8 — the upgrade imports the prices,
also while billing stays off. When the 0.5.7 currency differs, the import logs a warning and
`GET /api/billing/settings` reports `warnings: [{"code": "legacy_currency_mismatch", …}]` (shown on
top of Settings → Billing) until `BILLING_CURRENCY` matches it. Going back to 0.5.7 means restoring
the database backup taken before the upgrade ([Rolling back](upgrade.md#rolling-back)).

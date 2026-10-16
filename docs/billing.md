# Billing

RunR **calculates** what each organization owes. It meters and prices usage, applies plan fees and
capacity deals, and closes every calendar month with final amounts per tenant. Invoicing, taxes,
payment collection and refunds belong to the operator's billing system — an invoicing platform,
an ERP, a shop. That system reads the amounts through the **billing API**, receives **billing
events** on a signed webhook, and records what customers bought (credits, plan terms, add-ons) back
into RunR, so RunR's calculation reflects them.

Billing is part of the multi-tenant edition (the Tenancy module): an organization is a tenant.

```
 RunR                                                    your billing system
 ────────────────────────────────────────────            ──────────────────────────────────
 usage metering (every 5 min) ─┐
 cost configuration (prices) ──┼─▶ charges per tenant ──▶ billing.period.closed ─▶ invoice the customer
 plans, capacity deals ────────┘    and month              GET /api/billing/charges   collect payment
                                                                                       │
 credit balance, plan, limits ◀── POST /api/billing/tenants/{tenant}/purchases ◀──────┘
                               ◀── PUT  /api/billing/tenants/{tenant}/subscription
```

- [What RunR calculates](#what-runr-calculates)
- [Setup](#setup)
- [Billing API](#billing-api)
- [Charges](#charges)
- [Usage](#usage)
- [Plans and subscriptions](#plans-and-subscriptions)
- [Purchases](#purchases)
- [Billing events](#billing-events)
- [Example: invoicing a closed month](#example-invoicing-a-closed-month)
- [In the console](#in-the-console)
- [Permissions](#permissions)
- [Configuration reference](#configuration-reference)

---

## What RunR calculates

| Input | Where it comes from | What it adds |
|---|---|---|
| **Usage** | Metering every 5 minutes: function sessions (CPU millicore·ms, memory MiB·ms, duration, cold starts), always-on replicas per day, GPU time, model services, bindings (storage GB-hours, instance hours), sandboxes | Priced with the region's **cost configuration** (Settings → Billing → Configuration): price per CPU and memory unit, GPU price, binding prices, a multiplier per trigger (HTTP, queue, schedule) and currency — see [Monitoring and cost](monitoring.md#cost-formulas) |
| **Plans** (optional, off by default) | Settings → Plans & Billing | The **plan fee** per period (month or year), trial days, limits and feature flags — [Plans](#plans-and-subscriptions) |
| **Capacity deals** | `POST /api/tenants/{tenant}/capacity-deals` (platform administrators) | Reserved CPU / memory in a region for a **monthly price** |
| **One-off charges** | Platform administrator or the billing API | A fixed amount with a description |
| **Purchases** | Your billing system, through the billing API | **Credit** that closed charges draw on, **prepaid plan terms** (no plan fee), **add-ons** that raise plan limits — [Purchases](#purchases) |

From these RunR keeps **charges**: what a tenant owes for a period, with line items.

| Charge kind | Created | Amount |
|---|---|---|
| `usage` | one per tenant, calendar month and currency of its usage | Σ priced usage of the month: one line per project × region × scale mode × trigger, per model service, per binding, per region of sandbox usage |
| `plan` | when a plan period starts (assignment, renewal, a trial that ends) | the plan's monthly or yearly price |
| `capacity_deal` | daily for every active deal, once per month | the deal's monthly price |
| `manual` | `POST /api/billing/tenants/{tenant}/charges` | the given amount |

Usage is charged for every tenant while plans are off. With plans on, it is charged for tenants
without a plan and for tenants whose plan has the `metered_usage` feature; on other plans usage is
included in the plan fee (still metered and readable through `GET …/usage`).

A charge belongs to the **billing period** — the calendar month — its `period_start` falls in. A
charge is `open` while its month runs: its line items and total are calculated on request and can
still change. Every day at 02:30 (`charge-schedule`, application time zone, UTC by default) RunR
closes every open charge of a month that has ended: line items and total become final, the
tenant's credit balance is drawn on (`credit_applied`), the rest is `amount_due`, and one
`billing.period.closed` event per tenant and month announces it. A month the scheduler missed
closes on the next run. `void` charges are not owed.

Amounts are rounded to 2 decimals; there is no currency conversion — usage in another currency
than the tenant's has a usage charge of its own. The tenant's currency is its plan's currency, else
`BILLING_DEFAULT_CURRENCY` (default `USD`).

---

## Setup

1. **Webhook (optional).** Settings → Plans & Billing → Billing integration, or the Helm values:

   ```yaml
   billing:
     webhookUrl: https://billing.example.com/hooks/runr   # empty: events are kept for GET /api/billing/events only
     webhookSecret: "<random string, at least 16 characters>"
     usageThresholds: "100,500,1000"                       # month-to-date usage amounts → billing.usage.threshold
     storeUrl: https://shop.example.com                    # where tenants buy plans and credits (console link)
   ```

   **Send test event** on the settings page (or `POST /api/billing/events/test`) delivers a
   `billing.test` event and shows the result.

2. **API token.** Sign in as a platform administrator, leave any tenant (no active organization)
   and create a token in Settings → API Tokens with **only** `billing.view` (read) or
   `billing.manage` (read and write). The same with the API:

   ```bash
   curl -sS -X POST "$RUNR/api/tokens" -H "Authorization: Bearer $ADMIN_SESSION_TOKEN" \
     -H 'Content-Type: application/json' -d '{"name": "billing-system", "abilities": ["billing.manage"]}'
   ```

   The token reaches the billing API and, like every signed-in user, its own account and
   notifications — nothing else ([Permissions](#permissions)).

3. **Prices.** Set the cost configuration (Settings → Billing → Configuration) and, with plans
   on, the plans (Settings → Plans & Billing).

---

## Billing API

Base path `/api`, JSON, `Authorization: Bearer <token>`. `{tenant}` is the tenant id or its slug.
Reads need `billing.view`, writes `billing.manage` — as a platform administrator's session (the
console) or token.

| Method | Path | Purpose |
|---|---|---|
| GET | `/billing/tenants` | every tenant with its plan (`subscription`) |
| GET | `/billing/tenants/{tenant}` | one tenant: plan, effective limits (plan + add-ons), credit balances, currency, open charges with their amounts so far |
| GET | `/billing/tenants/{tenant}/usage` | rated usage of a period: `?period=YYYY-MM` or `?from=YYYY-MM-DD&to=YYYY-MM-DD` (at most 366 days); default: this month so far |
| GET | `/billing/charges` | charges, newest period first: `?tenant=` `?period=YYYY-MM` `?status=open\|closed\|void` `?kind=` `?closed_since=<ISO 8601>` `?external_ref=<ref>\|none` `?per_page=` (≤ 200) |
| GET | `/billing/charges/{charge}` | one charge; an open one with its line items and total as of now |
| PATCH | `/billing/charges/{charge}` | `{"external_ref": "INV-2026-0042"}` — your reference (your invoice number); `null` clears it |
| POST | `/billing/charges/{charge}/void` | `{"reason"?}` — not owed; a closed charge's credit goes back to the balance |
| POST | `/billing/tenants/{tenant}/charges` | one-off charge `{"description", "amount", "currency"?, "period_start"?, "period_end"?}` |
| GET | `/billing/tenants/{tenant}/subscription` | the tenant's plan |
| PUT | `/billing/tenants/{tenant}/subscription` | change the plan: `{"plan": "<id or slug>" \| null, "interval"?: "month"\|"year", "external_ref"?}`, or `{"cancel_at_period_end": true\|false}` |
| GET | `/billing/plans` | the plan catalog (archived plans included) |
| GET | `/billing/purchases` | purchases: `?tenant=` `?kind=` `?per_page=` |
| GET | `/billing/purchases/{purchase}` | one purchase |
| POST | `/billing/tenants/{tenant}/purchases` | record a purchase — [Purchases](#purchases) |
| POST | `/billing/purchases/{purchase}/void` | the purchase was reversed in your system |
| GET | `/billing/events` | billing events, oldest first (`?order=desc`: newest first): `?after=<event id>` `?before=<event id>` `?type=` `?tenant=` `?limit=` (≤ 500, default 100) |
| POST | `/billing/events/{event}/redeliver` | deliver an event to the webhook again |
| POST | `/billing/events/test` | record and deliver a `billing.test` event |

Lists answer `{"data": [...], "meta": {"current_page", "last_page", "per_page", "total"}}`; the
event list answers `{"data": [...], "meta": {"next_after", "next_before", "webhook_configured"}}`.
`after` and `before` are event ids that bound the list (only events recorded after / before that
one); the next page of an oldest-first list is `?after=<meta.next_after>`, of a newest-first list
`?order=desc&before=<meta.next_before>` — read on until it is `null` (the other cursor is always
`null`). Errors: `401` no or invalid token, `403` with the
permission that is needed (`{"permissions": ["billing.manage"]}`) or for users who are not platform
administrators, `404` unknown tenant / charge / purchase / event, `409` plans are off (plan
changes) or no webhook is configured (test, redeliver), `422` validation errors.

---

## Charges

```json
{
  "id": "01JBX7G3T2Q6M6Z9Y2F1V0R8KD",
  "tenant_id": "9b2f6a3e-5c1d-4c47-9a8e-0f6b2d7c1e44",
  "kind": "usage",
  "description": "Usage 2026-10",
  "period": "2026-10",
  "period_start": "2026-10-01",
  "period_end": "2026-10-31",
  "currency": "USD",
  "status": "closed",
  "final": true,
  "amount": 0,
  "line_items": [
    {
      "description": "Project: orders-api (HTTP, Scale-to-Zero)",
      "quantity": 15000,
      "unit": "sessions",
      "unit_price": 0.000234,
      "total": 3.51,
      "metadata": {"source": "project", "project_id": "01J…", "region_id": "01J…", "scale_mode": "scale_to_zero",
                   "entry_point": "http", "multiplier": 5, "raw_cost": 0.702, "days": 31}
    },
    {
      "description": "Binding: ledger (Postgres, dedicated)",
      "quantity": 14, "unit": "GB-hours", "unit_price": 0.117143, "total": 1.64,
      "metadata": {"source": "binding", "binding_id": "01J…", "type": "postgres", "plan": "dedicated", "region_id": "01J…",
                   "storage_gb_hours": 14, "instance_hours": 3}
    },
    {
      "description": "Sandboxes (eu-1)",
      "quantity": 2, "unit": "hours", "unit_price": 1.5, "total": 3,
      "metadata": {"source": "sandbox", "region_id": "01J…", "sandbox_count": 2, "sandbox_seconds": 7200}
    }
  ],
  "total": 8.15,
  "credit_applied": 0,
  "amount_due": 8.15,
  "external_ref": null,
  "capacity_deal_id": null,
  "closed_at": "2026-11-01T02:30:04+00:00",
  "voided_at": null,
  "created_at": "2026-10-01T02:30:01+00:00",
  "updated_at": "2026-11-01T02:30:04+00:00"
}
```

| Field | Meaning |
|---|---|
| `amount` | the fixed amount of a plan, capacity-deal or manual charge (`0` for usage) |
| `line_items` | final once closed; for an open charge the lines as of now (`GET /billing/charges/{charge}`, `GET /billing/tenants/{tenant}`), `null` in lists |
| `total` | Σ line totals |
| `credit_applied` | drawn from the tenant's credit balance when the charge closed |
| `amount_due` | `total − credit_applied` — what your system invoices |
| `final` | `true` once closed: the amounts do not change any more |
| `external_ref` | your reference, set with `PATCH` |

Line units: `sessions` (scale-to-zero functions), `days` (always-on replicas, model services),
`GB-hours` / `instance-hours` (bindings), `hours` (sandboxes), `month` (capacity deals), `period`
(plan fee), `item` (manual).

Credit is drawn per currency, oldest month first and within a month in the order of the charges'
period start. A credit bought after a month closed is drawn by the next month that closes. Plan
fees are per period and not prorated: a plan change starts a new period with the new plan's fee,
and the charge of the period that was running stays as calculated — void it when your system
settles it differently.

---

## Usage

`GET /api/billing/tenants/acme/usage?period=2026-10`

```json
{
  "tenant_id": "9b2f6a3e-5c1d-4c47-9a8e-0f6b2d7c1e44",
  "from": "2026-10-01",
  "to": "2026-10-31",
  "sessions": 15000,
  "currencies": {
    "USD": {"line_items": [ … the same lines as the usage charge … ], "total": 8.15}
  }
}
```

The usage endpoint rates any range with the prices stored at metering time — the same calculation
the usage charge closes with — also for tenants whose plan includes usage. `sessions` is the
number of function sessions in the range (the plan limit `invocations_per_month` counts these).

---

## Plans and subscriptions

Plans are **optional and off by default** (Settings → Plans & Billing, Helm `plans.enabled`). A
plan has prices per month and year in one currency, limits (CPU / memory written to the tenant's
region quotas, projects, GPUs, invocations per month, code storage), feature flags and trial days
— see [White-label → Plans](white-label.md#3-plans).

A tenant's plan changes through:

| Who | How | Plan fee |
|---|---|---|
| Your billing system | `PUT /api/billing/tenants/{tenant}/subscription` | calculated by RunR per period (`plan` charges) |
| Your billing system, for a term sold in your shop | a `plan` purchase | none — the term is **prepaid** |
| Platform administrator | Settings → Plans & Billing → Tenants (the same API) | calculated per period |
| Self-service signup | the default plan | calculated per period (usually a free plan) |

```bash
curl -sS -X PUT "$RUNR/api/billing/tenants/acme/subscription" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"plan": "pro", "interval": "year", "external_ref": "sub_7781"}'
```

```json
{
  "subscription": {
    "id": "01JBX…", "tenant_id": "9b2f…", "plan_id": "01JA…",
    "plan": {"id": "01JA…", "slug": "pro", "name": "Pro", "currency": "USD", "price_monthly": 49, "price_yearly": 490,
             "trial_days": 0, "is_default": false, "limits": {…}, "features": {…}},
    "interval": "year", "status": "active", "prepaid": false, "external_ref": "sub_7781",
    "trial_ends_at": null, "current_period_start": "2026-10-14T09:12:00+00:00",
    "current_period_end": "2027-10-14T09:12:00+00:00", "cancel_at_period_end": false
  }
}
```

- A new plan starts a new period now; the plan's quota is written to every region of the tenant.
- A plan with trial days starts in `trialing` once per tenant; the first fee is calculated when the
  trial ends.
- `{"plan": null}` removes the plan now (no plan limits; usage charged as without a plan).
- `{"cancel_at_period_end": true}` ends the plan with its period; `false` lets it renew again.
- Daily (`plan-renewal`, 01:30): periods that ended roll over with the next plan fee; trials
  convert; prepaid terms and plans cancelled at period end end. An ending plan falls back to the
  **free default plan** when there is one, otherwise the subscription is `canceled`.
- Status: `trialing`, `active`, `canceled`. Every change raises `billing.plan.changed`.

---

## Purchases

What a customer bought in your system, recorded with your reference so that RunR's calculation
reflects it. `external_ref` is the idempotency key: recording the same reference again answers
`200` with the stored purchase (`201` the first time); the same reference for another tenant or
another kind answers `409`.

`POST /api/billing/tenants/{tenant}/purchases`

| `kind` | Fields | Effect |
|---|---|---|
| `credit` | `amount` (> 0), `currency` | The tenant's credit balance grows; closed charges in that currency draw on it (`credit_applied`) |
| `plan` | `plan` (id or slug), `interval` (`month` default, `year`); `amount`, `currency` optional | The plan is in effect at once for one interval, **prepaid**: no plan fee is calculated for the term. The same plan and interval bought again while its prepaid term runs extends the term by one interval. A term that is not extended ends: the free default plan, else `canceled`. Needs plans on |
| `addon` | `limits` — any of `cpu_quota_m`, `memory_quota_mb`, `max_projects`, `gpu_quota`, `invocations_per_month`, `storage_quota_mb` with values > 0; `ends_at` (default: one month); `amount`, `currency` optional | The limits add to the tenant's plan limits until `ends_at` (an unlimited plan limit stays unlimited); CPU and memory raise the region quotas at once and go back when the add-on ends. Needs plans on |

Every kind takes `description`, `purchased_at` (default: now) and `metadata` (up to 50 string
values, shown to platform administrators only).

```bash
curl -sS -X POST "$RUNR/api/billing/tenants/acme/purchases" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"kind": "credit", "external_ref": "order-1001", "amount": 50, "currency": "USD", "description": "Credit pack 50"}'
```

```json
{
  "purchase": {"id": "01JBY…", "tenant_id": "9b2f…", "kind": "credit", "external_ref": "order-1001",
               "description": "Credit pack 50", "amount": 50, "currency": "USD", "plan": null, "interval": null,
               "limits": null, "starts_at": null, "ends_at": null, "purchased_at": "2026-11-02T10:00:00+00:00",
               "voided_at": null, "metadata": {}, "created_at": "2026-11-02T10:00:00+00:00"},
  "subscription": null,
  "credit_balances": {"USD": 50}
}
```

`POST /api/billing/purchases/{purchase}/void` when the purchase was reversed: a voided credit
leaves the balance (credit already drawn stays on the closed charges), a voided add-on's limits end
at once. A voided plan purchase leaves the running plan as it is — change it with the
subscription endpoint.

---

## Billing events

| Type | When | `data` |
|---|---|---|
| `billing.period.closed` | a tenant's charges of a month closed (once per tenant and month; a charge added later for a closed month closes in a later run with a new event for the whole month) | `period`, `period_start`, `period_end`, `closed_charge_ids` (closed in this run), `charges` (every closed charge of the month), `totals` per currency (`total`, `credit_applied`, `amount_due`), `credit_balances` |
| `billing.plan.changed` | a plan was assigned, changed, removed, prepaid, extended, cancelled at period end, ended, or a trial converted | `source` (`api` — the billing API and the console —, `purchase`, `signup`, `renewal`), `previous` (`plan`, `interval`, `status`, `prepaid`), `subscription` |
| `billing.usage.threshold` | once per tenant and month: the month-to-date usage in a currency reached a configured amount (`kind: amount` — `currency`, `threshold`, `amount`), or with plans on the month's sessions reached 80 % / 100 % of the plan's `invocations_per_month` including add-ons (`kind: limit` — `limit`, `percent`, `used`, `max`, `plan`). Checked hourly | see left |
| `billing.charge.voided` | a closed charge was voided | `charge` |
| `billing.test` | Send test event | `message` |

Every event has the same envelope — exactly the body the webhook receives:

```json
{
  "id": "01JBZ3Q9N8M2A6B4C7D5E1F0GH",
  "type": "billing.period.closed",
  "created_at": "2026-11-01T02:30:05+00:00",
  "tenant": {"id": "9b2f6a3e-5c1d-4c47-9a8e-0f6b2d7c1e44", "slug": "acme", "name": "Acme"},
  "data": {
    "closed_charge_ids": ["01JBX7G3T2…", "01JBX7G3T3…"],
    "period": "2026-10",
    "period_start": "2026-10-01",
    "period_end": "2026-10-31",
    "charges": [ … charges as in the API … ],
    "totals": {"USD": {"total": 57.15, "credit_applied": 20, "amount_due": 37.15}},
    "credit_balances": {"USD": 0}
  }
}
```

**Delivery.** With a webhook URL, RunR POSTs each event to it, oldest first:

```
POST /hooks/runr HTTP/1.1
Content-Type: application/json
User-Agent: RunR-Billing/1
X-RunR-Event: billing.period.closed
X-RunR-Delivery: 01JBZ3Q9N8M2A6B4C7D5E1F0GH
X-RunR-Timestamp: 1793500205
X-RunR-Signature: sha256=<hex HMAC-SHA256(webhook secret, "<X-RunR-Timestamp>.<raw body>")>
```

A `2xx` answer delivers the event. Anything else, a timeout (10 s) or a refused connection is
retried after 1, 5, 15, 60, 180, 360, 720 and 1 440 minutes, up to 10 attempts (then the event's
delivery status is `failed`; `POST /api/billing/events/{event}/redeliver` queues it again). A
failed delivery holds back the later events until the next attempt, about a minute later.
Delivery is at least once: deduplicate by `id` (also `X-RunR-Delivery`). Redirects are not
followed. Without a secret the `X-RunR-Signature` header is left out.

Verify the signature over the raw body, and the timestamp against your clock:

```python
import hashlib, hmac, time

def verify(secret: bytes, timestamp: str, raw_body: bytes, signature: str, tolerance: int = 300) -> bool:
    if abs(time.time() - int(timestamp)) > tolerance:
        return False
    expected = "sha256=" + hmac.new(secret, timestamp.encode() + b"." + raw_body, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, signature)
```

**Event log.** Every event is kept for 90 days (`BILLING_EVENTS_RETENTION_DAYS`), with or without
a webhook. `GET /api/billing/events?after=<id>` returns the events after the last one you
processed — a webhook outage is caught up by reading the log; each event carries its `delivery`
status, attempts and last error. `GET /api/billing/events?order=desc&limit=20` returns the 20 most
recent events.

---

## Example: invoicing a closed month

Your billing system holds a `billing.manage` token and the webhook secret.

1. **The month ends.** On 1 November at 02:30 RunR closes October. Your webhook receives one
   `billing.period.closed` event per tenant. Verify the signature, then store the event id (to
   ignore a redelivery).

2. **Read what is owed.** The event carries the closed charges. To work from the API instead —
   for example in a nightly job, or to catch up after downtime:

   ```bash
   curl -sS "$RUNR/api/billing/charges?status=closed&period=2026-10&external_ref=none&per_page=200" \
     -H "Authorization: Bearer $TOKEN"
   ```

   Invoice each tenant's `amount_due` per currency, with the line items as invoice lines. Charges
   with `amount_due` 0 (credit covered them, a free month) need no invoice.

3. **Mark the charges as invoiced** with your invoice number — the `external_ref=none` filter of
   step 2 then skips them on the next run:

   ```bash
   curl -sS -X PATCH "$RUNR/api/billing/charges/01JBX7G3T2Q6M6Z9Y2F1V0R8KD" -H "Authorization: Bearer $TOKEN" \
     -H 'Content-Type: application/json' -d '{"external_ref": "INV-2026-0042"}'
   ```

4. **Collect the payment** in your system, as you do for any invoice. RunR is not involved.

5. **Record what the customer bought.** The customer upgrades to Pro in your shop and buys a
   credit pack:

   ```bash
   # a prepaid plan term (no plan fee in RunR for it)
   curl -sS -X POST "$RUNR/api/billing/tenants/acme/purchases" -H "Authorization: Bearer $TOKEN" \
     -H 'Content-Type: application/json' -d '{"kind": "plan", "external_ref": "order-1002", "plan": "pro", "interval": "month", "amount": 49, "currency": "USD"}'

   # credit for usage
   curl -sS -X POST "$RUNR/api/billing/tenants/acme/purchases" -H "Authorization: Bearer $TOKEN" \
     -H 'Content-Type: application/json' -d '{"kind": "credit", "external_ref": "order-1003", "amount": 20, "currency": "USD"}'
   ```

   The plan is in effect at once (quotas, limits; `billing.plan.changed` with `source: purchase`).
   When November closes, its usage charge draws the 20 USD credit and the event's `totals` show
   `credit_applied: 20`. If you bill the plan as a subscription in your system instead, put the
   plan into effect with `PUT /api/billing/tenants/acme/subscription` — RunR then adds the plan fee
   to each period's charges.

6. **Watch usage during the month** (optional): `billing.usage.threshold` events tell you when a
   tenant's month-to-date usage passes your thresholds or nears its plan's invocation limit — a
   moment to offer an upgrade or an add-on.

---

## In the console

| Where | Who | What |
|---|---|---|
| Settings → Plans & Billing | platform administrators | plans on/off, the billing integration (webhook URL and secret, usage thresholds, store URL, test event, recent events with redelivery), the plan catalog, every tenant's plan |
| Settings → Billing | platform administrators | cost configuration, per tenant: usage and cost, charges with line items (void, one-off charges), credit balance and purchases |
| Settings → Subscription | every member of a tenant (plans on) | the plan, its period, limits and usage, the public plans; with `billing.manage` a link to the store (`storeUrl`) |
| Settings → Charges (in a tenant) | `billing.view` (Owner, Admin, Billing) | the tenant's charges with line items and amounts due, credit balance, purchases |

---

## Permissions

| Permission | In a tenant | With a platform administrator's token |
|---|---|---|
| `billing.view` | the tenant's charges, credit and purchases (Settings → Charges) | every read of the billing API |
| `billing.manage` (includes `billing.view`) | the store link in Settings → Subscription | every write of the billing API: plan changes, purchases, charges, event redelivery and test |

The billing API is authorized with `@platform:billing.view` / `@platform:billing.manage` rules
(`api/config/route_permissions.php`): the caller is a platform administrator, and an API token must
carry the permission. A tenant's own `billing.*` permissions never open the billing API of other
tenants. See [Roles and permissions](permissions.md).

---

## Configuration reference

| Env | Helm value | Default | Meaning |
|---|---|---|---|
| `BILLING_WEBHOOK_URL` | `billing.webhookUrl` | empty | where billing events are POSTed; empty: event log only |
| `BILLING_WEBHOOK_SECRET` | `billing.webhookSecret` (Secret `runr-secret`) | empty | HMAC-SHA256 key of `X-RunR-Signature` |
| `BILLING_USAGE_THRESHOLDS` | `billing.usageThresholds` | empty | month-to-date usage amounts, comma separated |
| `BILLING_STORE_URL` | `billing.storeUrl` | empty | store link for tenants |
| `PLANS_ENABLED` | `plans.enabled` | `false` | plans on |
| `BILLING_DEFAULT_CURRENCY` | `extraEnv` | `USD` | currency of tenants without a plan and of usage without a cost configuration |
| `BILLING_WEBHOOK_TIMEOUT` | `extraEnv` | `10` | seconds per delivery attempt |
| `BILLING_WEBHOOK_MAX_ATTEMPTS` | `extraEnv` | `10` | attempts per event |
| `BILLING_EVENTS_RETENTION_DAYS` | `extraEnv` | `90` | days events are kept |

Values saved in Settings → Plans & Billing win over the Helm values; clearing them there brings the
Helm values back. The webhook secret is stored encrypted (`secure_settings`, rotated by
`php artisan secrets:encrypt --rotate`) and never returned by the API.

Scheduled jobs: `usage-aggregation` (every 5 minutes), `charge-schedule` (daily 02:30),
`capacity-deal-expiry` (hourly), `plan-renewal` (daily 01:30, plans on),
`billing-usage-thresholds` (hourly), `billing-event-delivery` (every minute),
`billing-event-retention` (daily).

Tables (central database): `charges`, `billing_purchases`, `billing_events`, `plans`,
`tenant_subscriptions`, `cost_configs`, `capacity_deals`; `usage_records` and
`binding_usage_records` in each tenant's schema, `sandbox_usage_records` centrally.

# API product

A function owner can offer the API behind their [gateway](architecture.md#endpoints) to other
people: every consumer gets API keys, every key or consumer gets rate limits and quotas, and RunR
measures each consumer's calls — how many were let through or refused, and what the function
answered: status, latency, errors and bytes. RunR does not sell or invoice anything. An external system reads
the usage through the API or receives it as signed webhook events, and bills the consumers itself.

- [Concepts](#concepts)
- [How it works](#how-it-works)
- [Routes: public, API key, JWT](#routes)
- [Consumers](#consumers)
- [API keys](#api-keys)
- [Plans](#plans)
- [Calling a protected route](#calling-a-protected-route)
- [When gateway-auth is not ready](#when-gateway-auth-is-not-ready)
- [Usage](#usage)
- [Usage events](#usage-events)
- [Only through the gateway](#only-through-the-gateway)
- [Custom domain](#custom-domain)
- [Developer page](#developer-page)
- [Permissions](#permissions)
- [API](#api)
- [CLI and MCP](#cli-and-mcp)
- [Region requirements and operation](#region-requirements-and-operation)
- [Limits of this version](#limits-of-this-version)

## Concepts

| Term | What it is |
| --- | --- |
| Gateway | One per user and region: `https://<user>.<region>.<base domain>` (or its [custom domain](#custom-domain)), routes by path to projects |
| Route | A path of the gateway (`/orders`) and the project it reaches — its own workload, or one of its [environments](environments.md#gateway-routes) (`staging`) in the gateway's region. Its authentication is `public`, `key` or `jwt` |
| Consumer | Someone who calls the API, such as a customer of the function owner. A consumer belongs to one gateway, or to the organization (every gateway accepts it) |
| API key | A secret of a consumer, shown once. A key can be limited to some routes and methods, can expire, can be rotated with an overlap and can be revoked |
| Plan | Rate limits and quotas: requests per second (with a burst), per minute, per day and per calendar month. A plan belongs to a gateway or to the organization |
| Usage | Calls per hour, gateway, route, consumer and key: let through, refused (401, 403) and limited (429), and what the function answered to the calls let through (status classes, errors, latency percentiles, bytes) |

A route may lead to a [global project](global-projects.md#gateways) from a gateway in any of the
project's regions: the gateway hands requests to that region's copy. "Only through the gateway"
is refused for global projects (it would close their global host name).

## How it works

```
caller ──HTTPS──▶ region Traefik ── gateway IngressRoute gw-<id>
                   │  route /orders (auth: key)
                   │    1. rateLimit per client IP (optional, the gateway's)
                   │    2. forwardAuth ──▶ runr-gateway-auth /auth/<route>   ─┐ 401 / 403 / 429
                   │    3. stripPrefix, Host rewrite                         │ back to the caller
                   ▼                                                         │ 200: let through
                 runr-gateway-auth-proxy (metering) ◀── X-Runr-Consumer, X-Runr-Key, ticket
                   │  status, latency and bytes of the answer
                   ▼
                 region Traefik (web) ──▶ project (Knative Service)

runr-gateway-auth ── every 10 s: GET  /api/internal/gateway-auth/<region>/config (ETag, 304)
                  ── every 30 s: POST /api/internal/gateway-auth/<region>/usage
                  ── counters:   the region's Redis (or the pod's memory without Redis)
```

- The region's **gateway-auth** service (`ghcr.io/brf-tech/runr/gateway-auth`, source in
  `runtime/gateway-auth`) runs in the region's project namespace. RunR installs it with the first
  key or JWT route of the region and updates it with `region:reconcile`. One service serves every
  organization's gateways in the region.
- Traefik asks the service before every call of a key or JWT route (Traefik
  [forwardAuth](https://doc.traefik.io/traefik/middlewares/http/forwardauth/), Middleware
  `gw-auth-<route>`). The service checks the key or token, the consumer, the key's scopes and the
  plan. It answers 200 to let the call through, or 401, 403 or 429, which go back to the caller.
- The service never gets a key from RunR. It gets the SHA-256 hash of each usable key, and hashes
  what a caller sends. Revoked and expired keys are left out.
- The service fetches changes every `SYNC_SECONDS` (default 10) with an ETag. RunR answers 304 until a
  key, consumer, plan or route changes. A revoked key stops working within that interval.
- Each pod counts every decision and reports the counts to RunR every 30 seconds. RunR adds them to
  hourly rows and applies a report once (by its batch id), also when it is sent again.
- The calls a key or JWT route lets through then pass the service's **metering proxy** (Service
  `runr-gateway-auth-proxy`), which hands them on unchanged — Host, path, headers and body,
  streamed, WebSockets and server-sent events included — to the region Traefik, where the route's
  project answers as before. It records what the function answered under the consumer, key and
  route the check decided on: the status class, the latency until the answer started, the bytes
  of request and answer, and calls that got no answer ([Usage](#usage)). The check hands the proxy
  a signed ticket of its decision (`X-Runr-Gateway-Ticket`, valid 60 seconds, keyed with the
  region's gateway-auth token); the proxy serves nothing without one (403
  `not_through_gateway`), so a pod in the cluster can neither reach a function past its route's
  check nor count calls for a consumer, and the function never sees the ticket.
- A route of a project in access mode allowlist or "only through the gateway" has no public route
  on web: its rule names the project's own service in `X-Runr-Gateway-Upstream` (a value derived
  from the route and RunR's application key), which only the gateway's upstream IngressRoute
  `gw-<id>-upstream` matches. The client's address is still checked on the first hop.
- A route goes through the proxy once a pod of it is ready and no pod of an earlier image answers
  checks (an upgrade's rollout). Until then its calls go to the project directly, checked and
  counted but not measured, and the health job moves them onto the proxy within a minute
  ([When gateway-auth is not ready](#when-gateway-auth-is-not-ready)).
- Counters for the limits are kept in the region's Redis (the queue triggers' Redis, [regions.md](regions.md)),
  shared by the service's pods (2 by default). A region without Redis runs one pod that counts in
  memory, and its counters start over when the pod restarts. When Redis does not answer, the
  pod counts in memory and does not refuse every call.
- Key and JWT routes **fail closed**: while the service is not installed or not ready, they answer
  503 `auth_unavailable` and never reach the function unchecked
  ([When gateway-auth is not ready](#when-gateway-auth-is-not-ready)).

## Routes

A route is added in the console (**Build & Run → API gateways → Routes → Add route**: the project, the
environment, the path, its authentication) or with `POST /api/gateways/{gateway}/routes`
`{"project_id": "…", "environment": "staging", "path": "/staging", "methods": ["GET"], "auth_mode": "key"}`.
Without `environment` (or with `production`) the route leads to the project's own workload, which
then uses the gateway endpoint (`endpoint_type: gateway`); with one it leads to that environment's
Knative Service `<slug>-<name>`. The gateway must be in the region the project or environment runs
in (422 otherwise), a path is used once per gateway (422 `path`, on a change of the path too), and a
project environment has one route. `POST /api/gateways {"knative_region_id": "…"}` gives you your
gateway in a region where you have none yet (the console: **Gateway in another region**), e.g. for
an environment that runs elsewhere than its project. Removing a route
(`DELETE /api/gateways/{gateway}/routes/{route}`, the console's trash icon) syncs the gateway without
it; deleting an environment or a project removes its routes.

A new route takes its authentication with it: `auth_mode`, `jwt` and `gateway_only` in the `POST`,
validated as on a change. A key or JWT route reaches the region with its check in front of it — the
forwardAuth Middleware is applied before the IngressRoute that references it — so it is never
public, not even for a moment; the console, `runr gateway routes add --auth`, the MCP tool
`gateway_route_add` and Terraform's `runr_gateway_route` all create it in one call. The answers of
`POST` and `PATCH` are the route as the gateway shows it (`url`, `auth_mode`, `jwt`,
`gateway_only`, its project and environment), like the routes of `GET /api/gateways/{gateway}`.

Authentication is changed per route in the console (**Build & Run → API gateways → Routes**), through
`PATCH /api/gateways/{gateway}/routes/{route}` or `runr gateway routes set`.

| `auth_mode` | Caller sends | Checked |
| --- | --- | --- |
| `public` (default) | nothing | only the gateway's optional IP rate limit |
| `key` | an API key: `X-Api-Key: rk_…` or `Authorization: Bearer rk_…` | key known and not expired, consumer of this gateway (or of the organization), not suspended, route and method within the key's scopes, plan limits |
| `jwt` | `Authorization: Bearer <JWT>` | signature with a key of the route's JWKS (RS256/384/512, PS256/384/512, ES256/384/512; never `none` or HMAC), `exp` / `nbf` (30 s leeway), `iss` and `aud` when set; the subject's consumer and its plan |

JWT settings of a route (`jwt` object):

| Field | Meaning |
| --- | --- |
| `jwks_url` | the issuer's JWKS (required). It is fetched from inside the region, so an issuer in the cluster (`http://keycloak.idp.svc…`) works. Cached for 10 minutes and fetched again when a token names an unknown `kid` (at most every 30 seconds) |
| `issuer`, `audience` | expected `iss` and `aud` (optional; `aud` may be a list in the token) |
| `subject_claim` | the claim that names the consumer (default `sub`), matched against the consumer's `jwt_subject` |
| `require_consumer` | `true`: a valid token whose subject is not a consumer gets 403. `false` (default): it passes, without a plan, and its usage is counted under the subject |

What the function receives on a call that was let through:

| Header | Value |
| --- | --- |
| `X-Runr-Consumer` | the consumer's id |
| `X-Runr-Consumer-External` | the consumer's `external_id` (the invoicing system's customer number), when set |
| `X-Runr-Key` | the key's id (key routes) |
| `X-Runr-Subject` | the JWT subject (JWT routes) |

A caller cannot set these headers itself, because Traefik removes them from the request before it
copies the service's answer. On key routes `X-Api-Key` and `Authorization` are removed as well, so
the function never sees the key. On JWT routes the function keeps `Authorization` and can read the
token's other claims.

## Consumers

```bash
curl -X POST "$RUNR/api/api-consumers" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"gateway_id": "01JG…", "name": "Acme Ltd", "email": "api@acme.example", "external_id": "CUST-1042", "plan_id": "01JP…"}'
```

| Field | Meaning |
| --- | --- |
| `gateway_id` | the gateway. Leave it out for a consumer of the organization (`api_products.manage`) |
| `name`, `email` | who it is. The contact is shown only to those who may manage the consumer |
| `external_id` | the customer number of your invoicing system. It is in the usage, the events and `X-Runr-Consumer-External` |
| `jwt_subject` | JWT routes: the claim value that names this consumer (unique among the consumers a gateway accepts) |
| `plan_id` | its plan: one of the gateway's plans or of the organization's (an organization consumer can only use organization plans) |
| `status` | `active` or `suspended`. All keys of a suspended consumer get 403 (`consumer_suspended`) |
| `metadata` | free key / value pairs for your own use |

Deleting a consumer deletes its keys. Its usage stays in the reports.

## API keys

```bash
curl -X POST "$RUNR/api/api-consumers/01JC…/keys" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name": "production", "scopes": [{"route_id": "01JR…", "methods": ["GET"]}], "expires_at": "2027-01-01T00:00:00Z"}'
# → 201 {"data": {"id": "01JK…", "prefix": "rk_Hk2v9QzL", "status": "active", …, "key": "rk_Hk2v9QzL…"}}
```

- A key is `rk_` followed by 43 random letters and digits (about 256 bits). It is in the answer that
  creates or rotates it, and nowhere else. RunR keeps its SHA-256 hash and the first 11 characters
  (`prefix`) to tell keys apart. Keys are not written to the audit log or to logs; the audit keeps
  the key's id and prefix.
- `scopes`: `[{route_id, methods}]` limits the key to these routes of the consumer's gateway (all
  gateways of the organization for an organization consumer). `methods` limits it to some methods;
  leave it out for every method. Leave `scopes` out for every route.
- `plan_id` gives the key a plan of its own. Its limits are then counted for this key alone, not for
  the consumer.
- **Rotation:** `POST /api/api-keys/{key}/rotate` creates a key with the same scopes, plan and
  expiry. The old key keeps working for `overlap_minutes` (default 1440, `0` revokes it at once).
- **Revocation:** `POST /api/api-keys/{key}/revoke`. The key gets 401 within the sync interval.
  Revocation cannot be undone.

## Plans

| Field | Limit | Answer when reached |
| --- | --- | --- |
| `rate_per_second`, `burst` | token bucket: `burst` requests at once (default: the rate), refilled at the rate | 429 `rate_limited` |
| `rate_per_minute` | sliding window: the previous minute counts by how much of it still overlaps | 429 `rate_limited` |
| `quota_per_day` | calendar day, UTC | 429 `quota_exceeded` |
| `quota_per_month` | calendar month, UTC | 429 `quota_exceeded` |

Every limit is optional. A call is counted only when it passes every limit, so a call refused by
the per-second rate does not use up the day's quota. Limits apply per consumer, or per key when the
key has its own plan. A consumer without a plan has no limits beyond the gateway's IP rate limit.
A plan that a consumer or an active key uses cannot be deleted.

## Calling a protected route

```bash
curl https://jane.eu-1.runr.example/orders/42 -H 'X-Api-Key: rk_Hk2v9QzL…'
```

| Status | `error` | When |
| --- | --- | --- |
| 401 | `missing_key`, `invalid_key`, `key_expired`, `missing_token`, `invalid_token` | no or wrong credential (`WWW-Authenticate: Bearer realm="runr"`) |
| 403 | `wrong_gateway`, `route_not_allowed`, `method_not_allowed`, `consumer_suspended`, `unknown_subject` | the credential is valid but does not open this call |
| 429 | `rate_limited`, `quota_exceeded` | a plan limit; `RateLimit-Limit`, `RateLimit-Remaining: 0`, `RateLimit-Reset` and `Retry-After` (seconds), `RateLimit-Policy: <limit>;w=<window seconds>` |
| 503 | `auth_unavailable` | gateway-auth is not installed or not ready in the region ([below](#when-gateway-auth-is-not-ready); `Retry-After: 30`) |
| 503 | `not_ready`, `route_unknown` | a gateway-auth pod is starting, or has not loaded a route change yet (`Retry-After: 5`) |
| 502 | `upstream_unavailable` | the call was let through and the route's project gave no answer (counted as an upstream error) |
| 504 | `upstream_timeout` | the same, in time |

The body is JSON: `{"error": "<code>", "message": "<text>"}`. Answers that let a call through cannot
carry `RateLimit-*` headers: Traefik's forwardAuth returns the function's own answer.

## When gateway-auth is not ready

A key or JWT route never serves a call that nobody checked. It always reaches the function
through its forwardAuth Middleware `gw-auth-<route>`. While gateway-auth cannot check calls, that
Middleware asks the region's status responder instead (`runr-status-responder` in the Knative
Serving namespace, outside the project namespace and its quota). Every call then gets:

```
HTTP/1.1 503 Service Unavailable
Retry-After: 30
Content-Type: application/json

{"error":"auth_unavailable","message":"This API checks keys or tokens, and that check is not available right now. Try again shortly."}
```

| Situation | The routes | The gateway (`status`, `status_message`) |
| --- | --- | --- |
| A route is switched to `key` or `jwt` and gateway-auth is installed now | 503 `auth_unavailable` until a pod is ready (seconds), then checked | `pending` with the reason, then `active` |
| gateway-auth cannot be installed (namespace quota, an image the region cannot pull, …) | 503 `auth_unavailable` | `error`: "API authentication could not be installed…" |
| No pod gets ready within 5 minutes (it cannot reach RunR, it crashes) | 503 `auth_unavailable` | `error`: "API authentication is not ready…" |
| A ready gateway-auth stops being ready | 503 `auth_unavailable` within a minute; until then a starting pod answers 503 `not_ready` and Traefik answers 500 when no pod runs | `error` or `pending` |
| A new key or JWT route on a gateway whose gateway-auth is down | 503 `auth_unavailable` like the others | `error` |
| The route's Middleware cannot be applied | not served at all (Traefik answers 404) | `error`: names the route |
| The gateway's IngressRoute cannot be applied | the previous IngressRoute is deleted, so none of the gateway's routes is served | `error` |

A job checks gateway-auth every minute. It opens the routes when a pod is ready, closes them again
when none is, retries a failed installation and moves the routes onto the metering proxy once it
takes calls (`GatewayAuthHealthJob`). The console shows the
gateway's status and message in **Build & Run → API gateways**, and `GET /api/gateways/{id}` returns
them.

## Usage

`GET /api/api-usage` reads the hourly rows (UTC). The current hour grows until it ends. Every row
and the totals carry the gateway's decisions and what the function answered to the calls let
through.

| Query | Meaning |
| --- | --- |
| `gateway_id`, `consumer_id`, `key_id`, `route_id` | filters |
| `from`, `to` | ISO 8601 (default: the last 7 days); at most 400 days |
| `group_by` | `hour`, `day` (default), `month`, `consumer`, `key` or `route` |

```json
{
  "from": "2026-10-01T00:00:00Z", "to": "2026-11-01T00:00:00Z", "group_by": "consumer",
  "totals": {"requests": 120345, "unauthorized": 211, "forbidden": 3, "rate_limited": 940, "quota_exceeded": 12,
             "responses": 120345, "status_2xx": 119800, "status_3xx": 0, "status_4xx": 420, "status_5xx": 118,
             "upstream_errors": 7, "errors": 125, "latency_avg_ms": 41.3, "latency_p50_ms": 27.4,
             "latency_p95_ms": 118.2, "latency_p99_ms": 410, "bytes_in": 9842211, "bytes_out": 512330871},
  "data": [{"consumer_id": "01JC…", "consumer": {"id": "01JC…", "name": "Acme Ltd", "external_id": "CUST-1042"},
            "subject": null, "requests": 120000, "unauthorized": 0, "forbidden": 3, "rate_limited": 940, "quota_exceeded": 12,
            "responses": 120000, "status_2xx": 119460, "status_3xx": 0, "status_4xx": 418, "status_5xx": 115,
            "upstream_errors": 7, "errors": 122, "latency_avg_ms": 41.2, "latency_p50_ms": 27.4,
            "latency_p95_ms": 118, "latency_p99_ms": 409.6, "bytes_in": 9800102, "bytes_out": 510991240}]
}
```

The gateway's decisions:

| Field | Meaning |
| --- | --- |
| `requests` | calls let through to the function |
| `unauthorized`, `forbidden`, `rate_limited`, `quota_exceeded` | calls the gateway refused (401, 403, 429 rate, 429 quota) |

What the function answered to the calls let through, measured by the metering proxy:

| Field | Meaning |
| --- | --- |
| `responses` | calls the proxy handed on: every answer and every call without one |
| `status_2xx`, `status_3xx`, `status_4xx`, `status_5xx` | answers by status class; a WebSocket's `101` counts as `2xx` |
| `upstream_errors` | calls that got no answer at all: the route's project did not answer, the caller got 502 or 504 |
| `errors` | `status_5xx` + `upstream_errors` |
| `latency_avg_ms`, `latency_p50_ms`, `latency_p95_ms`, `latency_p99_ms` | milliseconds from the proxy receiving the call to the function's answer starting (its headers); for an upgraded connection (WebSocket) its handshake, until the `101`; `null` without an answer in the group |
| `bytes_in`, `bytes_out` | bytes of the request and answer bodies; of an upgraded connection (WebSocket), everything it carried from the caller and from the function, added to its hour's row when the connection closes |

The percentiles come from a latency histogram every row keeps (20 buckets with the bounds 2, 5, 10,
20, 30, 50, 75, 100, 150, 200, 300, 500, 750, 1000, 2000, 3000, 5000, 10 000 and 30 000 ms). A
group — an hour, a month, a consumer, a key, a route — adds the buckets of its rows and
interpolates inside the bucket a percentile falls in, so a percentile is exact to its bucket;
above 30 s it reads 30 000.

Refused calls without a known consumer (no key, unknown key) have `consumer_id: null`. JWT callers
that are not consumers are listed by `subject`. Public routes have no consumer and are not
measured (`responses: 0`), nor are hours before 0.6.0. Hourly rows are kept for 400 days
(`apiProduct.usageRetentionDays`). The console shows the same under **Build & Run → API gateways →
Usage**: the success rate, errors, p50 · p95 · p99 and bytes of the range, and the errors and
percentiles of every row (its status classes and bytes in the row's tooltip).

## Usage events

With the [Billing module](billing.md#webhook-events), usage is also recorded in the organization's
billing event log and sent to its signed webhook, with the same envelope, signature, retries and
`GET /api/billing/events` as every billing event:

| Type | When | `data` |
| --- | --- | --- |
| `api.usage.hourly` | about 5 minutes after an hour ends, per gateway with usage in it | `gateway`, `period` (`2026-10-21T10`), `from`, `to`, `totals`, `consumers[]` with their totals and `routes[]`; every total has the fields of [Usage](#usage): the decisions and what the function answered |
| `api.usage.monthly` | on the 1st, 00:20 UTC, per gateway with usage in the month before | as above, `period` `2026-10` |
| `api.usage.quota_exceeded` | a consumer's daily or monthly quota refused calls (once per consumer and day) | `gateway`, `consumer` (id, name, `external_id`), `key_id`, `route_id`, `plan` with its quotas, `hour`, `refused` |

Each consumer entry carries `consumer.external_id`, so an invoicing system can bill a month from
one `api.usage.monthly` event per gateway. Without the Billing module the usage API is the only
source.

## Only through the gateway

`gateway_only: true` on a route makes the project's Knative Service cluster-local, so its own
address `https://<slug>.<function domain>` stops serving and callers cannot get around the route's
keys. The gateway still reaches the project. A project with custom domains cannot be made gateway-only
(the domains would get around it), and a gateway-only project cannot get a custom domain. Removing
the route opens the project's own address again.

On a route to an environment it does the same to the environment's Service: its own address
`https://<slug>-<name>.<function domain>` stops serving, production's address does not change, and
the environment's custom domains are what keep it from becoming gateway-only.

## Custom domain

A gateway can be served on a domain of your own, such as `api.example.com`. Like a
project domain, it is proven by DNS before RunR routes it:

1. `PUT /api/gateways/{gateway}/custom-domain` with `{"domain": "api.example.com"}`. The answer's
   `custom_domain_pending` names the TXT record (`_runr-challenge.api.example.com` =
   `runr-verify=<token>`) and the records that route the name (a CNAME to the gateway's host, or
   an A / AAAA record of the region's external address).
2. Create the records, then `POST /api/gateways/{gateway}/custom-domain/verify`. When both are found
   the name becomes `custom_domain` and the gateway is synced. Otherwise the answer is 422 and
   says what is missing.
3. `DELETE /api/gateways/{gateway}/custom-domain` stops serving it.

A name used by another gateway or project, or under a region's function domain or the console's
domain, is refused. Only a verified name is ever written to `custom_domain`.

## Developer page

`PATCH /api/gateways/{gateway}` with `{"developer_page": true, "description": "…"}` publishes a
public page at `https://<console>/developers/<key>` (`developer_page_key` in the gateway). The page
shows the description, the base URLs, the routes (path, methods, authentication) and how to send a
key or token. It needs no sign-in and shows no project names. There is no self sign-up in this
version: the owner creates consumers and hands out keys.

`"developer_page_metrics": true` (console: **Domain and page**; `runr gateway page --metrics`)
adds each key or JWT route's health of the last 7 days to the page (`routes[].health`): the share
of answers without error and the latency p50, p95 and p99 — never call counts or consumers.

## Permissions

| Who | May |
| --- | --- |
| The gateway's owner (with `projects.update`), and `regions.manage` | manage the gateway's routes (authentication, gateway-only), consumers, keys, plans, domain and developer page; routing a project also needs `projects.update` on it |
| `projects.view` organization-wide | see every gateway, its consumers (without contact), plans and usage |
| `api_products.manage` (organization) | manage the organization's consumers and plans, accepted on every gateway; read the usage of every gateway |
| `billing.view` | read the usage of every gateway (the invoicing system's token) |

## API

| Method and path | Does |
| --- | --- |
| `GET /api/gateways`, `GET /api/gateways/{gateway}` | gateways with routes (`environment`, `auth_mode`, `jwt`, `gateway_only`), custom domain, developer page |
| `POST /api/gateways` | `knative_region_id`: your gateway in that region (201 created, 200 when you have one) |
| `PATCH /api/gateways/{gateway}` | `rate_limit`, `developer_page`, `description`, `developer_page_metrics` |
| `GET /api/gateways/{gateway}/routes` | the gateway's routes, each as the gateway shows it |
| `POST /api/gateways/{gateway}/routes` | `project_id`, `environment` (a name or id; default the project's own workload), `path`, `methods`, `auth_mode`, `jwt`, `gateway_only`; 201 with the route as the gateway shows it (`url`, `jwt`) |
| `PATCH /api/gateways/{gateway}/routes/{route}` | `path`, `methods`, `auth_mode`, `jwt`, `gateway_only`; the route as the gateway shows it |
| `DELETE /api/gateways/{gateway}/routes/{route}` | removes the route |
| `PUT` / `DELETE /api/gateways/{gateway}/custom-domain`, `POST …/custom-domain/verify` | the gateway's own domain |
| `GET` / `POST /api/api-consumers`, `GET` / `PATCH` / `DELETE /api/api-consumers/{consumer}` | consumers (`?gateway_id=`, `status`, `q`) |
| `GET` / `POST /api/api-consumers/{consumer}/keys` | keys of a consumer; the create answer has `key` |
| `PATCH /api/api-keys/{key}`, `POST …/rotate`, `POST …/revoke` | name, scopes, plan, expiry; rotation; revocation |
| `GET` / `POST /api/api-plans`, `PATCH` / `DELETE /api/api-plans/{plan}` | plans (`?gateway_id=`) |
| `GET /api/api-usage` | usage ([Usage](#usage)) |
| `GET /api/public/developer-pages/{key}` | a developer page, no sign-in |

Every change is in the [audit log](security.md#audit-log) (`api_consumer.*`, `api_key.*`,
`api_plan.*`, `gateway.create`, `gateway.domain.*`, `gateway.route.*`). Key secrets are never in it.

## CLI and MCP

`runr gateway` lists gateways and manages routes, consumers, keys, plans and usage
([cli.md](cli.md#api-gateways-runr-gateway)); `runr gateway usage` shows the errors and latency percentiles of every
row, `-o json` the whole answer. The MCP server offers `gateways_list`, `gateway_route_add` (a route
with its authentication, in one call), `gateway_route_update`,
`api_consumers_list`, `api_consumer_create`, `api_plans_list`, `api_plan_create`,
`api_key_create`, `api_key_rotate`, `api_key_revoke` (with `confirm: true`) and `api_usage`, whose
rows carry the answers' fields too ([mcp.md](mcp.md#tools)).

## Region requirements and operation

- The region's Traefik (installed by RunR) and its project namespace. Redis is optional (it gives
  shared counters and two pods).
- The region must reach RunR's API for the sync and the reports. The address is
  `apiProduct.callbackUrl`, or else the URL the region agent connects to ([regions.md](regions.md)).
- Objects in the project namespace: Secret, Deployment and Service `runr-gateway-auth` (requests
  50m CPU / 64 MiB, limits 1 CPU / 256 MiB per pod — the metering proxy streams the routes' calls
  through it —, non-root, read-only root filesystem), Service `runr-gateway-auth-proxy` (port 80
  to the pods' port `proxy`, 8081; ready pods only), one Middleware `gw-auth-<route>` per key or
  JWT route, and for the metered routes of projects in access mode allowlist or "only through the
  gateway" a Middleware `gw-up-<route>` and the gateway's IngressRoute and Middleware
  `gw-<id>-upstream` on the web entry point.
- `region:reconcile` applies gateway-auth again when a release changes its image or its
  Deployment (annotation `runr.sh/spec-revision`), and rebuilds the routes of every gateway whose
  IngressRoute does not carry the routing revision of the release (label
  `runr.dev/routing-revision`; step `gateway-routes`, after `gateway-auth`). A region upgraded from
  an earlier release so gets the current routing — the Host rewrite per route, the forwardAuth
  Middlewares, the direct routes of allowlist and gateway-only projects, the metering proxy —
  without waiting for a deploy or a route change; `--dry-run` names the gateways it would rebuild.
- A completed or rolled back base-domain or organization-domain migration rebuilds the region's
  gateways at once: their default host and their routes' Host rewrites follow the new domain
  ([regions.md](regions.md#changing-the-base-domain)).
- Helm values: `apiProduct.gatewayAuthImage`, `callbackUrl`, `syncSeconds`, `usageRetentionDays`
  ([helm-values.md](helm-values.md#apiproduct)). An air-gapped region needs the image in a
  registry it can reach.
- When gateway-auth cannot be installed or is not ready, the key and JWT routes answer 503
  `auth_unavailable` and the gateway shows `pending` or `error` with the reason
  ([When gateway-auth is not ready](#when-gateway-auth-is-not-ready)). The status responder in the
  Knative Serving namespace (`ghcr.io/brf-tech/runr/reqs/node:22-alpine`, 10m CPU / 16 MiB) is
  installed for this when needed.
- `runtime/ci.sh gateway-auth [--push]` builds the image. `runtime/ci.sh test-api-product` runs the
  Kind end-to-end suite.

## Limits of this version

- `RateLimit-*` headers are sent on 429 answers only.
- No self sign-up or key requests on the developer page.
- A gateway-only project depends on the gateway reaching cluster-local Services through the region
  Traefik's private entry point.

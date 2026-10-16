# White-label: branding, self-service signup and plans

RunR can be offered as a branded "serverless cloud in a box": your company's name, logos and
colours everywhere a user looks, self-service signup with e-mail verification, and optional plans
with prices, limits and quotas. RunR calculates what each organization owes; your own billing
system invoices and collects through the [billing API](billing.md).

Everything on this page is configured at runtime (admin UI) on top of the install values (Helm).
Nothing needs a rebuild of the console or the API.

| Part | Single-tenant | Multi-tenant |
|---|---|---|
| Branding (platform) | ✓ | ✓ |
| Branding per tenant | — | ✓ (when the platform allows it) |
| Self-service signup, e-mail verification, allowlist, invite-only, CAPTCHA, terms | ✓ | ✓ |
| Tenant per signup + onboarding wizard | — | ✓ |
| Plans (optional, off by default) | — | ✓ |
| Charges, billing API and billing events ([billing.md](billing.md)) | — | ✓ |

The default brand is **RunR by BRF Teknoloji** (RunR wordmark, RunR icon, RunR orange).

---

## 1. Branding

### 1.1 Fields

| Field | Meaning | Default |
|---|---|---|
| `product_name` | Console header, login page, browser title, e-mails | `RunR` |
| `company_name` | Copyright line of the login page and e-mails | `BRF Teknoloji` |
| `logo_light_url` | Logo on light backgrounds (light theme, e-mails) | RunR wordmark |
| `logo_dark_url` | Logo on dark backgrounds (dark themes, login page) | RunR wordmark |
| `logo_mark_url` | Square icon (compact header) | RunR icon |
| `favicon_url` | Browser tab icon | RunR icon |
| `primary_color` | Base colour `#rrggbb` — shade 500 of the primary scale | `#f97316` |
| `primary_scale` | Optional explicit shades 300–900 (`#rrggbb` or `r g b`) | derived from the base colour |
| `accent_color` | Highlight colour (`#rrggbb`) | `#e63946` |
| `login_headline`, `login_text` | Replace the login page carousel | empty (carousel) |
| `support_email`, `support_url` | Support contact on the login page and in e-mails | empty |
| `footer_links` | Up to 8 `{label, url}` links (`https://` or `mailto:`) | none |
| `powered_by` | Show "Powered by RunR" on a re-branded login page and in e-mails | `true` |
| `email_from_name` | Sender name of RunR's e-mails | product name |
| `email_logo_url` | Logo in e-mails | light logo |

Logo and favicon values accept an `https://` URL, a path on the console host
(`/brand/logo.svg`), or a `data:image/…;base64,…` URI (PNG, JPEG, WebP, GIF, SVG, ICO; up to
about 700 KB). SVGs are refused when they contain scripts, event handlers or embedded documents.
Logos can also be uploaded in the admin UI (stored on the API volume, served under `/storage`).

### 1.2 Precedence

Per field, the first value that is set wins:

1. **Tenant override** (multi-tenant, Settings → Organization Branding — only when the platform
   allows tenant branding; `powered_by` stays a platform decision)
2. **Platform override** (Settings → Branding)
3. **Install brand** (Helm values `branding.*`, applied when the pods start)
4. **RunR default**

"Reset to default" on a field in Settings → Branding removes the stored override, so the Helm
value applies again. Changing a Helm value later changes every field that was never overridden in
the admin UI.

### 1.3 Colours

The console's colour tokens are CSS variables driven by the branding:
`--brand-300` … `--brand-900` (the primary scale) and `--brand-accent`. The UI's Tailwind colour
tokens keep their names and read these variables, so every button, link, badge and focus ring
follows the brand.

- Give **one base colour** (`primary_color`, shade 500): RunR derives 300 / 400 by mixing with
  white (40 % / 20 %) and 600–900 by mixing with black (18 / 36 / 50 / 62 %).
- Or give the **full scale** (`primary_scale`) when your design system defines its own shades;
  shades you leave out are derived from the base colour.

Example preset **"Ocean"** (blue scale, also available as a preset button in Settings → Branding):

| Shade | Value |
|---|---|
| 300 | `102 197 237` |
| 400 | `51 181 233` |
| 500 | `0 159 227` |
| 600 | `0 123 192` |
| 700 | `0 85 184` |
| 800 | `0 68 153` |
| 900 | `0 43 128` |
| accent | `#009fe3` |

`GET /api/branding` returns the resolved `palette` (RGB triplets per shade) next to the colours.

### 1.4 Installing with your own brand via Helm values

```yaml
# values-brand.yaml — helm upgrade --install runr ./helm -f values-brand.yaml
domain: console.example.com
edition: multi-tenant

branding:
  productName: "Example Cloud"
  companyName: "Example Inc."
  logo:
    light: "https://cdn.example.com/brand/logo-light.svg"
    # a key of an existing ConfigMap / Secret in the release namespace; its content is a URL,
    # a data: URI or raw <svg> markup
    dark:
      configMapKeyRef: { name: example-brand, key: logo-dark.svg }
    mark: "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="
  favicon:
    secretKeyRef: { name: example-brand-secret, key: favicon }
  colors:
    # one base colour …
    # primary: "#0f766e"
    # … or the full scale ("Ocean" example preset)
    primary:
      "300": "102 197 237"
      "400": "51 181 233"
      "500": "0 159 227"
      "600": "0 123 192"
      "700": "0 85 184"
      "800": "0 68 153"
      "900": "0 43 128"
    accent: "#009fe3"
  loginHeadline: "Serverless functions for your teams"
  loginText: "Deploy code in seconds on our private cloud."
  supportUrl: "https://support.example.com"
  supportEmail: "support@example.com"
  footerLinks:
    - { label: "Status", url: "https://status.example.com" }
    - { label: "Terms", url: "https://example.com/terms" }
  poweredBy: true
  email:
    fromName: "Example Cloud"
    logo: "https://cdn.example.com/brand/logo-mail.png"   # PNG reads best in mail clients
  tenantOverrides: true
```

Create the referenced ConfigMap / Secret before the release, for example:

```bash
kubectl -n runr create configmap example-brand --from-file=logo-dark.svg=./logo-dark.svg
kubectl -n runr create secret generic example-brand-secret --from-file=favicon=./favicon.png.b64
```

(`favicon.png.b64` holds a `data:image/png;base64,…` line.) The chart validates every
`branding.*` value against `helm/values.schema.json` (URLs, data: URIs, colour formats, links).

The values become environment variables of the API, Horizon and scheduler pods
(`BRANDING_PRODUCT_NAME`, `BRANDING_COMPANY_NAME`, `BRANDING_LOGO_LIGHT`, `BRANDING_LOGO_DARK`,
`BRANDING_LOGO_MARK`, `BRANDING_FAVICON`, `BRANDING_PRIMARY_COLOR`, `BRANDING_PRIMARY_SCALE`,
`BRANDING_ACCENT_COLOR`, `BRANDING_LOGIN_HEADLINE`, `BRANDING_LOGIN_TEXT`,
`BRANDING_SUPPORT_EMAIL`, `BRANDING_SUPPORT_URL`, `BRANDING_FOOTER_LINKS`, `BRANDING_POWERED_BY`,
`BRANDING_EMAIL_FROM_NAME`, `BRANDING_EMAIL_LOGO`, `BRANDING_TENANT_OVERRIDES`); a ConfigMap /
Secret reference is passed with `valueFrom`.

### 1.5 Where the brand appears

- **Login page** (before sign-in): `GET /api/branding` (or `?tenant=<slug>` once a user picked an
  organization's tenant sign-in) — logos, colours, headline, footer links, support link, copyright line.
- **Console**: header logo / product name, document title, favicon, colours.
- **E-mails**: Laravel's mail theme is branded — header logo or product name, button colour,
  footer links, support contact, copyright line, "Powered by RunR". Sender name:
  `email_from_name` (mails sent with the install's default sender name are renamed). Mails sent
  for a tenant (invitations) use that tenant's branding. A `data:` logo is served
  to mail clients through `GET /api/branding/assets/email_logo`.
- **Brand images**: `GET /api/branding/assets/{logo_light|logo_dark|logo_mark|favicon|email_logo}`
  (`?tenant=<slug>`): the uploaded file or decoded data: URI (sandboxing Content-Security-Policy),
  a redirect for a URL value.

### 1.6 Admin UI and API

Settings → **Branding** (`branding.manage`; multi-tenant: platform administrators): every field,
logo uploads, colour presets with live swatches, per-field reset, the tenant-branding switch.
Settings → **Organization Branding** (multi-tenant, `branding.manage` in the tenant — owners and
admins by default): the same fields for the tenant.

| Method | Path | Who |
|---|---|---|
| GET | `/api/branding?tenant=<slug>` | public (`Cache-Control: public, max-age=60`) |
| GET | `/api/branding/assets/{kind}` | public (`Cache-Control: public, max-age=300`) |
| GET / PUT | `/api/admin/branding` | `branding.manage` (multi-tenant: platform administrators) |
| POST / DELETE | `/api/admin/branding/assets/{kind}` (multipart `file`) | same |
| GET / PUT | `/api/tenant/branding` | `branding.manage` in the tenant |
| POST / DELETE | `/api/tenant/branding/assets/{kind}` | `branding.manage` in the tenant |

`PUT` is a partial update; a field sent as `null` returns to its default.

---

## 2. Self-service signup

Registration stays **on by default** (`registration_enabled`, Settings → Platform or Settings →
Signup). Settings → **Signup** adds:

| Setting | Default | Effect |
|---|---|---|
| E-mail verification | off | The account is created only when the link in the e-mail is opened |
| Verification link lifetime | 48 h | After that the link answers 410; the user asks for a new one |
| Allowed domains | any | `example.com` (exact), `*.example.com` (sub-domains) |
| Invite-only | off | Public signup answers 403; users join through tenant invitations or admin-created accounts |
| CAPTCHA | none | Cloudflare Turnstile or hCaptcha |
| Terms / privacy URLs | none | When set, signup requires accepting them; the time is stored (`users.terms_accepted_at`) |
| Tenant per signup (multi-tenant) | on | Every signup gets its own organization |
| Default quota (multi-tenant) | 2000 m CPU / 4096 MB | Quota on the first region (plans off) |
| Self-service regions (multi-tenant) | all active platform regions | Regions the onboarding wizard offers |

### 2.1 E-mail verification

```
POST /api/auth/register      → 202 {verification_required: true, email}
  (signup stored in pending_signups: name, username, bcrypt password, workspace name, consent)
e-mail link  https://<console>/signup/verify?token=…
POST /api/auth/signup/verify {token} → 201 {user, token, tenants, active_tenant}
POST /api/auth/signup/resend {email} → 200 (same answer for every address; at most once a minute)
```

No account, no tenant and no database schema exists for an unverified address. Signing up again
with the same address replaces the waiting signup; a username held by another waiting signup is
refused. Expired waiting signups are removed after a week (scheduled daily).

### 2.2 CAPTCHA

1. Create a site at Cloudflare Turnstile or hCaptcha for the console host name.
2. Settings → Signup: provider, site key, secret key (write-only, stored encrypted) — or Helm
   `signup.captcha.{provider,siteKey,secret}`.
3. The signup form renders the widget; the API verifies the token with the provider's
   `siteverify` endpoint once every other field is valid (a token is single-use).

A provider selected without a secret, an unreachable provider or an unexpected answer rejects the
signup (fail closed) and is logged.

### 2.3 Tenant per signup and onboarding (multi-tenant)

The signup's tenant is named from the form ("Workspace name") or `<name>'s workspace`; the user
becomes its owner. With plans on, the tenant starts on the **default plan** (when one is marked
default). On the first sign-in the owner sees the **onboarding wizard**:

1. **Organization name** (renames the tenant)
2. **Region** — asked only when several self-service regions are offered; with one region it is
   assigned directly. The quota is the plan's (plans on) or the default signup quota, lowered to
   what is still free of the region capacity (the organizations' quotas on a region add up to at
   most its capacity; inside a quota, scale-to-zero projects overcommit by the region's factors,
   [Organization quotas](architecture.md#region-capacity-and-admission)).

Tenants created by a platform administrator never see the wizard. Other plans are put into effect
by the platform administrator or by your billing system ([billing.md](billing.md#plans-and-subscriptions)).

| Method | Path | Who |
|---|---|---|
| GET | `/api/auth/registration-status` | public (enabled, invite-only, verification, domains, CAPTCHA site key, terms, tenant per signup) |
| POST | `/api/auth/register` | public (`captcha_token`, `accept_terms`, `tenant_name`) |
| POST | `/api/auth/signup/verify`, `/api/auth/signup/resend` | public |
| GET / PUT | `/api/admin/signup-settings` | `signup.manage` (single-tenant; multi-tenant: platform administrators) |
| GET | `/api/onboarding` | every tenant member (status, offered regions, default quota, the tenant's plan) |
| POST | `/api/onboarding` `{org_name, region_id?}` | `settings.manage` in the tenant |

---

## 3. Plans

Plans are **off by default**. While off, no plan appears anywhere — not in the console, not in the
API's public listing — and tenant quotas are managed per region by the platform administrator as
before.

Switch them on in Settings → **Plans & Billing** (or Helm `plans.enabled: true`). A plan is an
input of RunR's calculation: its price is the plan fee per period, its limits and features apply
to the tenants on it. How plans are put into effect, renewed and ended — and how your billing
system changes them or sells prepaid terms and add-ons — is described in
[billing.md](billing.md#plans-and-subscriptions).

### 3.1 A plan

| Field | Meaning |
|---|---|
| `slug`, `name`, `description` | Identity and the text of the plan card |
| `currency`, `price_monthly`, `price_yearly` | The plan fee per period; a plan without a yearly price is monthly only; a 0 price is a free plan |
| `limits.cpu_quota_m`, `limits.memory_quota_mb` | Written to the tenant's quota on **every region it uses** |
| `limits.max_projects` | Projects in the tenant |
| `limits.gpu_quota` | GPUs the tenant's workloads reserve over all regions: projects with `config.gpu` (or a GPU runtime version) and model services on device `gpu` that are not stopped, each `count × max(1, min_scale)`. Creating or changing a GPU project or model service beyond it is refused; the tenant's per-region [GPU quota](ai.md#gpu-quota) applies as well |
| `limits.invocations_per_month` | Function sessions metered this calendar month; when used up, no new deploys until the next month or a larger limit |
| `limits.storage_quota_mb` | Size of the tenant's code repository (projects + versions) on the API volume; when full, no new code |
| `features` | `custom_domains`, `private_connections`, `gpu`, `api_tokens`, `sso`, `metered_usage`, `priority_support` — a flag set to `false` switches the feature off; an unset flag is allowed. `metered_usage` (on only when set) charges the tenant's metered usage on top of the plan fee |
| `trial_days` | Free days at the start of a tenant's first plan (once per tenant) |
| `is_public` | Listed for tenants (Settings → Subscription) and on the public plan list |
| `is_default` | Given to new self-service tenants; the fallback when a plan ends |
| `sort_order` | Order of the plan cards |

Limits left empty are unlimited. Add-ons bought in your system raise a tenant's limits for their
term ([billing.md](billing.md#purchases)). Refused requests answer `422 {code: "plan_limit", limit,
max, used}` (`limit: "feature", feature: …` for a feature flag). Platform administrators are never
limited.

A plan whose CPU / memory limit changes is written to the quotas of all its tenants. A plan in use
is archived instead of deleted (hidden, kept for its tenants).

### 3.2 What tenants see

Settings → **Subscription** (every member of the tenant): the plan, its status (`trialing`,
`active`, `canceled`), period and trial end, the limits with their usage (add-ons included) and the
public plans. Members with `billing.manage` (Owner, Admin, Billing) get a link to your store when
`billing.storeUrl` is set — that is where plans and credits are bought; your billing system then
records the purchase in RunR. Settings → **Charges** in the tenant (`billing.view`) lists the
tenant's charges, credit and purchases.

| Method | Path | Who |
|---|---|---|
| GET | `/api/plans/public` | public (plans on, otherwise `[]`) — e.g. for your pricing page |
| GET / POST | `/api/admin/plans` | platform administrators |
| PATCH / DELETE | `/api/admin/plans/{id}` | platform administrators |
| GET / PUT | `/api/admin/billing-settings` | platform administrators — plans on/off, billing webhook URL and secret (write-only), usage thresholds, store URL |
| GET | `/api/tenant/subscription` | every tenant member |
| GET | `/api/tenant/billing` | `billing.view` in the tenant |
| — | `/api/billing/…` | the billing API ([billing.md](billing.md#billing-api)) |

---

## 4. Helm values

```yaml
branding: { productName, companyName, logo: {light, dark, mark}, favicon, colors: {primary, accent},
            loginHeadline, loginText, supportUrl, supportEmail, footerLinks, poweredBy, email: {fromName, logo},
            tenantOverrides }                                        # section 1.4

signup:
  emailVerification: false
  verificationTtlHours: 48
  allowedDomains: ""          # "example.com,*.example.org"
  inviteOnly: false
  captcha: { provider: none, siteKey: "", secret: "" }   # turnstile | hcaptcha; secret → Secret
  termsUrl: ""
  privacyUrl: ""
  tenantAutoCreate: true
  defaultQuota: { cpuM: 2000, memoryMb: 4096 }
  regions: ""                 # region ids offered in the onboarding wizard

plans:
  enabled: false

billing:                      # docs/billing.md
  webhookUrl: ""              # billing events → your billing system
  webhookSecret: ""           # signs them; → Secret
  usageThresholds: ""         # "100,500,1000"
  storeUrl: ""                # where tenants buy plans and credits
```

Every value is an install default: a value saved in the admin UI wins. Secrets go to the release
Secret (`secretName`); with `secret.create: false` add the keys `BILLING_WEBHOOK_SECRET` and
`SIGNUP_CAPTCHA_SECRET` to the pre-created Secret yourself (both optional).

## 5. Permissions

Roles and permissions (`docs/permissions.md`) authorize every route; the white-label routes use:

| Permission | Allows | Built-in roles |
|---|---|---|
| `branding.manage` | single-tenant: the install's brand (Settings → Branding); multi-tenant: the tenant's own brand (Settings → Organization Branding) | Owner, Admin |
| `signup.manage` | the signup settings (single-tenant; Settings → Signup) | Owner, Admin |
| `settings.manage` | completing the onboarding wizard of a self-service tenant | Owner, Admin |
| `billing.view` | the tenant's charges, credit and purchases (Settings → Charges) | Owner, Admin, Billing |
| `billing.manage` | the store link in Settings → Subscription | Owner, Admin, Billing |
| platform administrators | multi-tenant: the install's brand and signup settings, plans, Plans & Billing settings, every tenant's plan; the billing API ([billing.md](billing.md#permissions)) | — |

Reading the tenant's plan (`GET /api/tenant/subscription`) and the onboarding status is open to
every tenant member.

## 6. Data

| Table | Content |
|---|---|
| `brandings` | Platform branding (`tenant_id` null) and tenant overrides, uploaded asset paths |
| `pending_signups` | Signups waiting for their e-mail verification (token stored as SHA-256) |
| `secure_settings` | Secrets entered in the admin UI (CAPTCHA secret, billing webhook secret), encrypted (`encrypted` cast) |
| `plans` | Plans with prices, limits and features |
| `tenant_subscriptions` | Plan, interval, status, prepaid flag, external reference and period of each tenant |
| `tenant_onboarding` | Onboarding wizard state of self-service tenants |
| `users.terms_accepted_at` | Terms / privacy acceptance at signup |

Charges, purchases and billing events: [billing.md](billing.md#configuration-reference).

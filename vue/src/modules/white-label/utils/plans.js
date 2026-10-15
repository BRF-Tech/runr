// Plan helpers shared by the plan editor, the plan cards, the subscription page and the billing
// settings.

export const LIMIT_KEYS = [
  'cpu_quota_m',
  'memory_quota_mb',
  'max_projects',
  'gpu_quota',
  'invocations_per_month',
  'storage_quota_mb',
]

export const FEATURE_FLAGS = [
  'custom_domains',
  'private_connections',
  'gpu',
  'api_tokens',
  'sso',
  'metered_usage',
  'priority_support',
]

/** Flags a new plan starts with off (metered_usage: a monthly usage charge on top of the plan fee). */
export const FEATURES_OFF_BY_DEFAULT = ['metered_usage']

export const featureDefault = (flag) => !FEATURES_OFF_BY_DEFAULT.includes(flag)

export const CURRENCIES = ['USD', 'EUR', 'TRY', 'GBP']

export const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'canceled']

/** "" / null / undefined / non-numeric → null (unlimited), otherwise a non-negative integer. */
export function toLimit(value) {
  if (value === null || value === undefined) return null
  const s = String(value).trim()
  if (s === '') return null
  const n = Number(s)
  return Number.isFinite(n) && n >= 0 ? Math.floor(n) : null
}

/** "" / null → null, otherwise a number rounded to 2 decimals. */
export function toPrice(value) {
  if (value === null || value === undefined) return null
  const s = String(value).trim().replace(',', '.')
  if (s === '') return null
  const n = Number(s)
  return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) / 100 : null
}

/** Empty editor form for a new plan. */
export function emptyPlanForm() {
  return {
    slug: '',
    name: '',
    description: '',
    currency: 'USD',
    price_monthly: '0',
    price_yearly: '',
    limits: Object.fromEntries(LIMIT_KEYS.map(k => [k, ''])),
    features: Object.fromEntries(FEATURE_FLAGS.map(f => [f, featureDefault(f)])),
    trial_days: '0',
    is_public: true,
    is_default: false,
    sort_order: '0',
  }
}

/** Editor form from an API plan (null limits → empty inputs). */
export function planToForm(plan) {
  if (!plan) return emptyPlanForm()
  const base = emptyPlanForm()
  return {
    ...base,
    slug: plan.slug ?? '',
    name: plan.name ?? '',
    description: plan.description ?? '',
    currency: plan.currency ?? 'USD',
    price_monthly: plan.price_monthly ?? 0,
    price_yearly: plan.price_yearly ?? '',
    limits: Object.fromEntries(LIMIT_KEYS.map(k => [k, plan.limits?.[k] ?? ''])),
    features: Object.fromEntries(FEATURE_FLAGS.map(f => [f, plan.features?.[f] ?? featureDefault(f)])),
    trial_days: plan.trial_days ?? 0,
    is_public: plan.is_public ?? true,
    is_default: !!plan.is_default,
    sort_order: plan.sort_order ?? 0,
  }
}

/** API payload of POST/PATCH /api/admin/plans from the editor form. */
export function buildPlanPayload(form) {
  return {
    slug: String(form.slug ?? '').trim(),
    name: String(form.name ?? '').trim(),
    description: String(form.description ?? '').trim() || null,
    currency: String(form.currency ?? 'USD').trim().toUpperCase(),
    price_monthly: toPrice(form.price_monthly) ?? 0,
    price_yearly: toPrice(form.price_yearly),
    limits: Object.fromEntries(LIMIT_KEYS.map(k => [k, toLimit(form.limits?.[k])])),
    features: Object.fromEntries(FEATURE_FLAGS.map(f => [f, !!form.features?.[f]])),
    trial_days: toLimit(form.trial_days) ?? 0,
    is_public: !!form.is_public,
    is_default: !!form.is_default,
    sort_order: toLimit(form.sort_order) ?? 0,
  }
}

/** Price of a plan for an interval (null when the plan has no yearly price). */
export function planPrice(plan, interval = 'month') {
  if (!plan) return null
  return interval === 'year' ? plan.price_yearly ?? null : plan.price_monthly ?? 0
}

export function formatMoney(amount, currency = 'USD', locale = 'en') {
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(locale === 'tr' ? 'tr-TR' : 'en-US', { style: 'currency', currency }).format(Number(amount))
  } catch {
    return `${Number(amount).toFixed(2)} ${currency}`
  }
}

/** true when any of the plans has a yearly price (shows the month/year toggle). */
export function hasYearly(plans) {
  return Array.isArray(plans) && plans.some(p => p.price_yearly !== null && p.price_yearly !== undefined)
}

/** "100, 500;1000" → [100, 500, 1000]: positive amounts, sorted, without duplicates. */
export function parseThresholds(text) {
  const values = String(text ?? '')
    .split(/[\s,;]+/)
    .map(v => toPrice(v))
    .filter(v => v !== null && v > 0)
  return [...new Set(values)].sort((a, b) => a - b)
}

/** [100, 500] → "100, 500" */
export function formatThresholds(list) {
  return Array.isArray(list) ? list.join(', ') : ''
}

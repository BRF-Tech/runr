// Helpers of the Billing module UI: who looks at it (install / platform / tenant, permissions),
// the price fields and number / money formatting.
import { useBrandingStore } from '@/modules/white-label/stores/branding'
import { activeTenant, currentUser, isMultiTenantBuild } from '@/modules/white-label/utils/context'
import { usePermissionsStore } from '@/stores/permissions'

/** The API runs the Billing module (GET /api/branding → features.billing). */
export function billingEnabled() {
  try {
    return useBrandingStore().features?.billing === true
  } catch {
    return false
  }
}

/**
 * The organization the console works in: 'install' (single-tenant), 'platform' (multi-tenant,
 * no active tenant) or 'tenant'.
 */
export function billingContext() {
  if (!isMultiTenantBuild()) return 'install'
  return activeTenant() ? 'tenant' : 'platform'
}

/** A permission of the signed-in user in the current organization (the API enforces). */
export function can(permission, projectId = null) {
  try {
    return usePermissionsStore().can(permission, projectId)
  } catch {
    return false
  }
}

export function isPlatformAdmin() {
  return !!currentUser()?.is_admin
}

/** Billing pages of the organization: billing enabled + billing.view (platform: administrators). */
export function canViewBilling() {
  if (!billingEnabled()) return false
  if (billingContext() === 'platform' && !isPlatformAdmin()) return false
  return can('billing.view')
}

/** Any signed-in user of an install with billing: the bills they pay. */
export function canSeeOwnBills() {
  return billingEnabled() && !!localStorage.getItem('token')
}

// ── Prices ───────────────────────────────────────────────────────────────────

/** Price fields of a price version, grouped as the editor shows them. */
export const PRICE_GROUPS = [
  { key: 'payg',        fields: ['cpu_vcpu_hour', 'memory_gb_hour', 'gpu_hour'] },
  { key: 'fixed',       fields: ['cpu_vcpu_month', 'memory_gb_month', 'gpu_month'] },
  { key: 'storage',     fields: ['kv_gb_hour', 'object_gb_hour', 'postgres_gb_hour', 'postgres_instance_hour'] },
  { key: 'multipliers', fields: ['multiplier_http', 'multiplier_queue', 'multiplier_schedule'] },
]

export const PRICE_FIELDS = PRICE_GROUPS.flatMap(g => g.fields)

export const BILLING_TYPES = ['payg', 'fixed']

/** Form values of a price version: the given (current) prices, numbers as strings. */
export function priceForm(prices = {}) {
  const form = { effective_from: today(), region_id: null }
  for (const field of PRICE_FIELDS) {
    const value = prices?.[field]
    form[field] = value === null || value === undefined ? '' : String(value)
  }
  return form
}

/** POST /billing/prices body of a form: every field a number ≥ 0 (complete versions only). */
export function pricePayload(form) {
  const body = { effective_from: form.effective_from, region_id: form.region_id || null }
  for (const field of PRICE_FIELDS) body[field] = Number(form[field])
  return body
}

/** The fields of a form that are not a number ≥ 0. */
export function invalidPriceFields(form) {
  return PRICE_FIELDS.filter((field) => {
    const raw = form[field]
    if (raw === '' || raw === null || raw === undefined) return true
    const n = Number(raw)
    return !Number.isFinite(n) || n < 0
  })
}

// ── Dates and periods ────────────────────────────────────────────────────────

/** Today as YYYY-MM-DD (local date). */
export function today(now = new Date()) {
  const y = now.getFullYear()
  const m = String(now.getMonth() + 1).padStart(2, '0')
  const d = String(now.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

/** The current billing period (YYYY-MM). */
export function currentPeriod(now = new Date()) {
  return today(now).slice(0, 7)
}

/** The last n periods, newest first (YYYY-MM). */
export function recentPeriods(n = 12, now = new Date()) {
  const out = []
  const d = new Date(now.getFullYear(), now.getMonth(), 1)
  for (let i = 0; i < n; i++) {
    out.push(currentPeriod(d))
    d.setMonth(d.getMonth() - 1)
  }
  return out
}

function intlLocale(locale) {
  return locale === 'tr' ? 'tr-TR' : 'en-US'
}

export function formatDate(value, locale = 'en') {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString(intlLocale(locale), { year: 'numeric', month: 'short', day: 'numeric' })
}

export function formatDateTime(value, locale = 'en') {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleString(intlLocale(locale), { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

/** "2026-10" → "October 2026". */
export function formatPeriod(period, locale = 'en') {
  if (!/^\d{4}-\d{2}$/.test(period || '')) return period || '—'
  const [y, m] = period.split('-').map(Number)
  return new Date(y, m - 1, 1).toLocaleDateString(intlLocale(locale), { year: 'numeric', month: 'long' })
}

// ── Numbers ──────────────────────────────────────────────────────────────────

export function formatMoney(amount, currency = 'USD', locale = 'en') {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(intlLocale(locale), { style: 'currency', currency: currency || 'USD' }).format(Number(amount))
  } catch {
    return `${Number(amount).toFixed(2)} ${currency || ''}`.trim()
  }
}

/** A unit price with up to 6 decimals (vCPU-hour prices are fractions of a cent). */
export function formatUnitPrice(amount, currency = 'USD', locale = 'en') {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(intlLocale(locale), {
      style: 'currency', currency: currency || 'USD', minimumFractionDigits: 2, maximumFractionDigits: 6,
    }).format(Number(amount))
  } catch {
    return `${Number(amount)} ${currency || ''}`.trim()
  }
}

export function formatNumber(value, locale = 'en', maxDigits = 2) {
  if (value === null || value === undefined || value === '') return '—'
  return new Intl.NumberFormat(intlLocale(locale), { maximumFractionDigits: maxDigits }).format(Number(value))
}

/** millicores → vCPU (1000m = 1 vCPU) */
export function vcpu(cpuM) {
  return Math.round((Number(cpuM) || 0) / 10) / 100
}

/** MB → GB (1024 MB = 1 GB) */
export function gb(memoryMb) {
  return Math.round(((Number(memoryMb) || 0) / 1024) * 100) / 100
}

/** The API's message of a failed request (first validation error, else message). */
export function apiMessage(error, fallback) {
  const data = error?.response?.data
  const first = data?.errors ? Object.values(data.errors).flat()[0] : null
  return first || data?.message || fallback
}

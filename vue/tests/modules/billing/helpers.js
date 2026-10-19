// Test helpers of the Billing module specs (Vitest + @vue/test-utils, jsdom).
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import billingEn from '@/modules/billing/locales/en'
import whiteLabelEn from '@/modules/white-label/locales/en'
import { usePermissionsStore } from '@/stores/permissions'
import { useBrandingStore } from '@/modules/white-label/stores/branding'

export function makeI18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { ...coreEn, ...whiteLabelEn, ...billingEn } },
  })
}

export function makePinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return pinia
}

/** global mount options: i18n + an active Pinia */
export function globalOptions(pinia = makePinia()) {
  return { plugins: [makeI18n(), pinia] }
}

export async function flush(times = 4) {
  for (let i = 0; i < times; i++) await new Promise(resolve => setTimeout(resolve, 0))
}

export const ok = (data, status = 200) => Promise.resolve({ data, status })

export const fail = (status, data = {}) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), { response: { status, data } }))

/** Permissions of the signed-in user in the current organization (GET /auth/permissions). */
export function grant(permissions = [], { platformAdmin = false, edition = 'single', projects = {} } = {}) {
  const store = usePermissionsStore()
  store.apply({ permissions, platform_admin: platformAdmin, edition, role: null, roles: [], projects })
  return store
}

/** The API's GET /branding with the Billing module on (or off). */
export function billingOn(on = true, extra = {}) {
  useBrandingStore().set({ features: { billing: on, tickets: true, multi_tenant: false, ...extra } })
}

export function signIn({ id = 'u1', admin = false, tenant = null } = {}) {
  localStorage.setItem('token', 'test-token')
  localStorage.setItem('user', JSON.stringify({ id, email: 'owner@example.com', is_admin: admin }))
  if (tenant) localStorage.setItem('active_tenant', JSON.stringify(tenant))
  else localStorage.removeItem('active_tenant')
}

export function bill(over = {}) {
  return {
    id: 'b-1', kind: 'project', period: '2026-09', period_start: '2026-09-01', period_end: '2026-09-30',
    status: 'closed', estimate: false, currency: 'USD', source: 'runr',
    project: { id: 'p1', name: 'orders-api' }, owner: { id: 'u1', name: 'Ada', email: 'ada@example.com' }, tenant: null,
    billing_type: 'payg', line_items: null, fixed_total: 0, usage_total: 12.5, total: 12.5,
    payment_url: null, payment_url_set_at: null, paid: false, paid_at: null, payment_reference: null,
    closed_at: '2026-10-01T02:30:00Z',
    ...over,
  }
}

export const LINES = [
  { type: 'usage', metric: 'vcpu_hours', description: 'vCPU hours (eu-1, HTTP)', quantity: 250, unit: 'vCPU-hours', unit_price: 0.04, multiplier: 1, markup_percent: 0, total: 10, from: '2026-09-01', to: '2026-09-30' },
  { type: 'usage', metric: 'memory_gb_hours', description: 'Memory GB hours (eu-1, HTTP)', quantity: 500, unit: 'GB-hours', unit_price: 0.005, multiplier: 1.5, markup_percent: 10, total: 2.5, from: '2026-09-01', to: '2026-09-30' },
]

export function fixedRequest(over = {}) {
  return {
    id: 'fr-1', project: { id: 'p1', name: 'orders-api' }, region: { id: 'r1', name: 'eu-1' },
    cpu_m: 2000, memory_mb: 4096, gpu_count: 0, vcpu: 2, memory_gb: 4, monthly_price: 62, currency: 'USD',
    status: 'pending', note: 'launch', requested_by: { id: 'u2', name: 'Bob' }, requested_at: '2026-10-18T09:00:00Z',
    decided_by: null, decided_at: null, decision_reason: null, active_from: null, active_until: null,
    ...over,
  }
}

export const PRICES = {
  cpu_vcpu_hour: 0.04, memory_gb_hour: 0.005, gpu_hour: 1.2, cpu_vcpu_month: 25, memory_gb_month: 3, gpu_month: 700,
  kv_gb_hour: 0.0002, object_gb_hour: 0.00004, postgres_gb_hour: 0.0002, postgres_instance_hour: 0.05,
  multiplier_http: 1, multiplier_queue: 1, multiplier_schedule: 1,
}

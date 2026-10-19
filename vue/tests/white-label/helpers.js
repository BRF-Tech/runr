// Test helpers for the white-label module (Vitest + @vue/test-utils, jsdom).
import { RouterLinkStub } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import coreEn from '@/locales/en'
import whiteLabelEn from '@/modules/white-label/locales/en'
import { usePermissionsStore } from '@/stores/permissions'

const Empty = { render: () => null }

export function makeI18n() {
  return createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { ...coreEn, ...whiteLabelEn } },
  })
}

export async function makeRouter(path = '/') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: Empty },
      { path: '/login', name: 'login', component: Empty, meta: { public: true } },
      { path: '/projects', name: 'projects', component: Empty },
      { path: '/settings', name: 'settings', component: Empty },
      { path: '/signup/verify', name: 'signup-verify', component: Empty, meta: { public: true } },
    ],
  })
  router.push(path)
  await router.isReady()
  return router
}

export function makePinia() {
  const pinia = createPinia()
  setActivePinia(pinia)
  return pinia
}

/** global mount options with i18n + pinia (+ router when given). */
export function globalOptions({ router = null, pinia = makePinia() } = {}) {
  return {
    plugins: [makeI18n(), pinia, ...(router ? [router] : [])],
    stubs: { RouterLink: RouterLinkStub },
  }
}

/** Resolve pending promises / microtasks (API mocks, async setup). */
export async function flush(times = 3) {
  for (let i = 0; i < times; i++) {
    await new Promise(resolve => setTimeout(resolve, 0))
  }
}

/** axios-like response */
export const ok = (data, status = 200) => Promise.resolve({ data, status })

/** axios-like error */
export const fail = (status, data = {}) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), { response: { status, data } }))

export function signIn({ admin = false, tenant = null } = {}) {
  localStorage.setItem('token', 'test-token')
  localStorage.setItem('user', JSON.stringify({ id: 'u1', email: 'owner@example.com', is_admin: admin }))
  if (tenant) localStorage.setItem('active_tenant', JSON.stringify(tenant))
  else localStorage.removeItem('active_tenant')
}

/**
 * Effective permissions of the signed-in user (the console's roles & permissions store, as
 * GET /auth/permissions answers). Call after makePinia() / globalOptions() made Pinia active.
 */
export function grant(permissions = [], { platformAdmin = false, edition = 'single' } = {}) {
  const store = usePermissionsStore()
  store.apply({ permissions, platform_admin: platformAdmin, edition, role: null, roles: [], projects: {} })
  return store
}

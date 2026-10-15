import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  activeTenant, canInOrg, canInTenant, canManageInstall, inTenantContext, isCentralAdmin, isMultiTenantBuild,
} from '@/modules/white-label/utils/context'
import { grant, makePinia, signIn } from './helpers'

vi.mock('@/api/client', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const tenant = { id: 't1', slug: 'acme', name: 'Acme', role: 'owner' }

describe('white-label context (who sees what)', () => {
  beforeEach(() => {
    localStorage.clear()
    makePinia()
  })
  afterEach(() => {
    vi.unstubAllEnvs()
  })

  it('reads the build edition', () => {
    vi.stubEnv('VITE_TENANCY_ENABLED', 'true')
    expect(isMultiTenantBuild()).toBe(true)
    vi.stubEnv('VITE_TENANCY_ENABLED', 'false')
    expect(isMultiTenantBuild()).toBe(false)
  })

  it('single-tenant: install settings follow the permission', () => {
    signIn()
    grant(['branding.manage'])
    expect(canManageInstall('branding.manage', false)).toBe(true)
    expect(canManageInstall('signup.manage', false)).toBe(false)
    expect(canInOrg('signup.manage')).toBe(false)
  })

  it('single-tenant: a platform admin holds every permission', () => {
    signIn({ admin: true })
    grant([], { platformAdmin: true })
    expect(canManageInstall('signup.manage', false)).toBe(true)
  })

  it('until the permissions are loaded the console shows the entry (the API decides)', () => {
    signIn()
    expect(canInOrg('branding.manage')).toBe(true)
  })

  it('multi-tenant: install settings are for the central administrator only', () => {
    signIn({ admin: true })
    grant(['branding.manage', 'signup.manage'], { platformAdmin: true, edition: 'multi' })
    expect(isCentralAdmin(true)).toBe(true)
    expect(canManageInstall('branding.manage', true)).toBe(true)

    // the same admin scoped into a tenant
    signIn({ admin: true, tenant })
    expect(isCentralAdmin(true)).toBe(false)
    expect(canManageInstall('branding.manage', true)).toBe(false)

    // a tenant owner never manages the install
    signIn({ admin: false, tenant })
    grant(['branding.manage', 'billing.manage'], { edition: 'multi' })
    expect(canManageInstall('branding.manage', true)).toBe(false)
  })

  it('multi-tenant: tenant permissions need an active tenant', () => {
    signIn({ tenant })
    grant(['billing.manage'], { edition: 'multi' })
    expect(activeTenant()).toEqual(tenant)
    expect(inTenantContext(true)).toBe(true)
    expect(canInTenant('billing.manage', true)).toBe(true)
    expect(canInTenant('branding.manage', true)).toBe(false)
    // single-tenant builds have no tenant pages
    expect(canInTenant('billing.manage', false)).toBe(false)

    signIn()
    expect(canInTenant('billing.manage', true)).toBe(false)
    expect(inTenantContext(true)).toBe(false)
  })
})

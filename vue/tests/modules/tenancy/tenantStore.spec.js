import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import client from '@/api/client'
import { tenantsApi } from '@/modules/tenancy/api/tenants'
import { useTenantStore } from '@/modules/tenancy/stores/tenant'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn() }, clearEtagCache: vi.fn() }))
vi.mock('@/modules/tenancy/api/tenants', () => ({ tenantsApi: { impersonate: vi.fn(), stopImpersonation: vi.fn() } }))

const ACME   = { id: 't-acme', name: 'Acme', slug: 'acme', role: 'admin', member: false }
const GLOBEX = { id: 't-globex', name: 'Globex', slug: 'globex', role: 'admin', member: true }

function store(tenants = [ACME, GLOBEX]) {
  setActivePinia(createPinia())
  const s = useTenantStore()
  s.tenants = tenants
  s.loaded = true
  return s
}

const saved = () => JSON.parse(localStorage.getItem('active_tenant') || 'null')

describe('tenant store — opening an organization as platform administrator', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
    client.get.mockResolvedValue({ data: { exists: true } })
    tenantsApi.impersonate.mockImplementation(async id => ({ data: { tenant: { id, impersonating: true } } }))
    tenantsApi.stopImpersonation.mockResolvedValue({ data: {} })
  })

  it('starts an impersonation for an organization the administrator is no member of', async () => {
    const s = store()

    expect(await s.switchTenant('t-acme')).toBe(true)

    expect(tenantsApi.impersonate).toHaveBeenCalledWith('t-acme')
    expect(s.isImpersonating).toBe(true)
    expect(saved()).toMatchObject({ id: 't-acme', impersonating: true })
  })

  it('switches into an own organization without impersonation', async () => {
    const s = store()

    expect(await s.switchTenant('t-globex')).toBe(true)

    expect(tenantsApi.impersonate).not.toHaveBeenCalled()
    expect(s.isImpersonating).toBe(false)
  })

  it('ends the impersonation when leaving for the platform or another organization', async () => {
    const s = store()
    await s.switchTenant('t-acme')

    await s.switchTenant('t-globex')
    expect(tenantsApi.stopImpersonation).toHaveBeenCalledWith('t-acme')
    expect(s.activeTenantId).toBe('t-globex')

    await s.switchTenant('t-acme')
    await s.stopImpersonation()
    expect(tenantsApi.stopImpersonation).toHaveBeenLastCalledWith('t-acme')
    expect(s.activeTenant).toBeNull()
    expect(saved()).toBeNull()
  })

  it('reads the membership flags from /tenant/current when the sign-in list has none', async () => {
    const s = store([{ id: 't-acme', name: 'Acme', slug: 'acme', role: 'admin' }])
    client.get.mockImplementation(async url => url === '/tenant/current'
      ? { data: { tenant: null, tenants: [ACME, GLOBEX] } }
      : { data: { exists: true } })

    await s.switchTenant('t-acme')

    expect(client.get).toHaveBeenCalledWith('/tenant/current')
    expect(tenantsApi.impersonate).toHaveBeenCalledWith('t-acme')
  })

  it('keeps the impersonation flag the API reports for the current organization', async () => {
    const s = store()
    client.get.mockResolvedValue({ data: { tenant: { id: 't-acme', name: 'Acme', slug: 'acme' }, role: 'admin', impersonating: true, tenants: [ACME] } })

    await s.fetchTenants()

    expect(s.isImpersonating).toBe(true)
    expect(saved()).toMatchObject({ id: 't-acme', impersonating: true })
  })

  it('a failed check leaves the current context alone', async () => {
    const s = store()
    client.get.mockRejectedValue(new Error('404'))

    expect(await s.switchTenant('t-acme')).toBe(false)
    expect(tenantsApi.impersonate).not.toHaveBeenCalled()
    expect(s.activeTenant).toBeNull()
  })
})

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

// Effective permissions of the signed-in user (GET /auth/permissions, docs/permissions.md)

vi.mock('@/api/rbac', () => ({ rbacApi: { myPermissions: vi.fn() } }))

import { usePermissionsStore } from '@/stores/permissions'
import { rbacApi } from '@/api/rbac'

const PAYLOAD = {
  edition: 'single', platform_admin: false, role: 'viewer', roles: ['viewer'],
  permissions: ['projects.view', 'logs.view'],
  projects: { P1: ['projects.deploy', 'code.edit'] },
  token_scoped: false,
}

describe('permissions store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.setItem('token', 't')
    rbacApi.myPermissions.mockReset()
    rbacApi.myPermissions.mockImplementation(() => Promise.resolve({ data: PAYLOAD }))
  })

  it('allows everything until loaded (the API decides)', () => {
    expect(usePermissionsStore().can('regions.manage')).toBe(true)
  })

  it('answers organization-wide and per-project permissions', async () => {
    const perms = usePermissionsStore()
    await perms.load()

    expect(perms.can('projects.view')).toBe(true)
    expect(perms.can('projects.deploy')).toBe(false)
    expect(perms.can('projects.deploy', 'P1')).toBe(true)
    expect(perms.can('projects.deploy', 'P2')).toBe(false)
    expect(perms.canAnywhere('code.edit')).toBe(true)
    expect(perms.canAnywhere('regions.manage')).toBe(false)
    expect(perms.role).toBe('viewer')
  })

  it('treats platform admins as allowed unless their token is scoped', () => {
    const perms = usePermissionsStore()
    perms.apply({ platform_admin: true, permissions: [], projects: {}, token_scoped: false })
    expect(perms.can('regions.manage')).toBe(true)

    perms.apply({ platform_admin: true, permissions: ['regions.view'], projects: {}, token_scoped: true })
    expect(perms.can('regions.manage')).toBe(false)
    expect(perms.can('regions.view')).toBe(true)
  })

  it('ignores a response that is not a permissions payload', () => {
    const perms = usePermissionsStore()
    perms.apply({ data: [] })
    expect(perms.loaded).toBe(false)
    expect(perms.can('projects.deploy')).toBe(true)
  })

  it('loads once at a time and not without a session', async () => {
    const perms = usePermissionsStore()
    await Promise.all([perms.load(), perms.load()])
    expect(rbacApi.myPermissions).toHaveBeenCalledTimes(1)

    localStorage.removeItem('token')
    await perms.load()
    expect(perms.loaded).toBe(false)
  })
})

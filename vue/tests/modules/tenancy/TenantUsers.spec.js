import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import { createPinia, setActivePinia } from 'pinia'
import coreEn from '@/locales/en'
import tenancyEn from '@/modules/tenancy/locales/en'

// The organization's users in the multi-tenant edition with the checks of the core users page
// (UsersManager.vue): add users and the invitations need members.invite, reset 2FA members.update,
// the role select roles.manage, remove members.remove (api/config/route_permissions.php).

const tutorial = vi.hoisted(() => ({ steps: null, start: vi.fn() }))

vi.mock('@/modules/tenancy/api/tenants', () => ({
  tenantsApi: {
    listUsers: vi.fn(), searchEmail: vi.fn(), addUser: vi.fn(), updateUser: vi.fn(), removeUser: vi.fn(),
    resendInvitation: vi.fn(), cancelInvitation: vi.fn(), resetTenantUser2FA: vi.fn(),
  },
}))
vi.mock('@/api/rbac', () => ({ rbacApi: { roles: vi.fn(), myPermissions: vi.fn() } }))
vi.mock('@/composables/useTutorial', () => ({
  createTutorial: (steps) => { tutorial.steps = steps; return { start: tutorial.start } },
}))

import TenantUsers from '@/modules/tenancy/components/TenantUsers.vue'
import { tenantsApi } from '@/modules/tenancy/api/tenants'
import { rbacApi } from '@/api/rbac'
import { usePermissionsStore } from '@/stores/permissions'
import { useTutorialStore } from '@/stores/tutorial'

const READ_ONLY = [
  'projects.view', 'git.pull', 'env.view', 'secret_stores.view', 'logs.view', 'metrics.view', 'traces.view',
  'workflows.view', 'sandboxes.view', 'regions.view', 'images.view', 'bindings.view', 'members.view',
]
const MANAGE = [...READ_ONLY, 'members.invite', 'members.update', 'members.remove', 'roles.manage']

const users = [
  { id: 1, name: 'Olivia Owner', email: 'o@acme.test', role: 'owner', two_factor_confirmed_at: null },
  { id: 2, name: 'Dev One', email: 'd@acme.test', role: 'developer', two_factor_confirmed_at: '2026-10-01T10:00:00Z' },
]
const invitations = [{ id: 9, email: 'new@acme.test', role: 'viewer' }]

const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

beforeEach(() => {
  vi.clearAllMocks()
  tutorial.steps = null
  localStorage.clear()
  localStorage.setItem('user', JSON.stringify({ id: 1 }))
  tenantsApi.listUsers.mockResolvedValue({ data: { users, invitations } })
  rbacApi.roles.mockResolvedValue({ data: { roles: [{ key: 'owner' }, { key: 'admin' }, { key: 'developer' }, { key: 'viewer' }], default_role: 'developer' } })
})

async function mountUsers(permissions) {
  setActivePinia(createPinia())
  const i18n = createI18n({
    legacy: false, locale: 'en', fallbackLocale: 'en', missingWarn: false, fallbackWarn: false,
    messages: { en: { ...coreEn, ...tenancyEn } },
  })
  if (permissions) {
    usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'multi', role: null, roles: [], projects: {} })
  }
  const w = mount(TenantUsers, { props: { tenantId: 't-acme' }, global: { plugins: [i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

const byTitle = (w, title) => w.findAll(`button[title="${title}"]`)

describe('TenantUsers permissions', () => {
  it('shows a read-only role the users and invitations without any action', async () => {
    const w = await mountUsers(READ_ONLY)

    expect(w.find('[data-tour="tenant-users-list"]').text()).toContain('Dev One')
    expect(w.text()).toContain('new@acme.test')
    expect(w.find('[data-action="add-user"]').exists()).toBe(false)
    expect(byTitle(w, 'Reset 2FA')).toHaveLength(0)
    expect(w.find('[data-user-role-select="2"]').exists()).toBe(false)
    expect(w.find('[data-user-role="2"]').text()).toBe('Developer')
    expect(byTitle(w, 'Remove User')).toHaveLength(0)
    expect(byTitle(w, 'Resend')).toHaveLength(0)
    expect(byTitle(w, 'Cancel Invitation')).toHaveLength(0)
  })

  it('shows every action with the permissions of the core users page', async () => {
    const w = await mountUsers(MANAGE)

    expect(w.find('[data-action="add-user"]').exists()).toBe(true)
    expect(byTitle(w, 'Reset 2FA')).toHaveLength(1)
    expect(w.find('[data-user-role-select="2"]').exists()).toBe(true)
    expect(byTitle(w, 'Remove User')).toHaveLength(1)
    expect(byTitle(w, 'Resend')).toHaveLength(1)
    expect(byTitle(w, 'Cancel Invitation')).toHaveLength(1)

    await w.find('[data-action="add-user"]').trigger('click')
    expect(w.find('[data-tour="tenant-users-form"]').exists()).toBe(true)
  })

  it('gates each action by its own permission', async () => {
    const w = await mountUsers([...READ_ONLY, 'members.remove'])

    expect(byTitle(w, 'Remove User')).toHaveLength(1)
    expect(w.find('[data-action="add-user"]').exists()).toBe(false)
    expect(byTitle(w, 'Reset 2FA')).toHaveLength(0)
    expect(w.find('[data-user-role-select="2"]').exists()).toBe(false)
  })

  it('does not open the add form on a tour restart without members.invite', async () => {
    const w = await mountUsers(READ_ONLY)

    useTutorialStore().requestRestart()
    await nextTick()
    await nextTick()

    expect(w.find('[data-tour="tenant-users-form"]').exists()).toBe(false)
    expect(tutorial.start).toHaveBeenCalled()
    // The list step tells what a read-only role can do there
    const steps = tutorial.steps((key) => key)
    expect(steps.map(s => s.popover.description)).toContain('tenancy.tutorialTenantUsersListDescReadOnly')
  })

  it('opens the add form on a tour restart with members.invite', async () => {
    const w = await mountUsers(MANAGE)

    useTutorialStore().requestRestart()
    await nextTick()
    await nextTick()

    expect(w.find('[data-tour="tenant-users-form"]').exists()).toBe(true)
    expect(tutorial.steps((key) => key).map(s => s.popover.description)).toContain('tenancy.tutorialTenantUsersListDesc')
  })
})

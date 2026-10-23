import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { deferred, withPlugins } from '../../helpers'

const rbacApi = vi.hoisted(() => ({
  groups: vi.fn(), roles: vi.fn(), members: vi.fn(), group: vi.fn(),
  addGroupMembers: vi.fn(), removeGroupMember: vi.fn(),
}))
vi.mock('@/api/rbac', () => ({ rbacApi }))

import GroupsManager from '@/components/rbac/GroupsManager.vue'

const GROUPS = [
  { id: 'g-a', name: 'alpha', member_count: 1 },
  { id: 'g-b', name: 'beta', member_count: 1 },
]
const detailOf = (id, member) => ({ data: { group: { id, members: [{ id: member, name: member, email: `${member}@example.com` }] } } })

async function mountManager() {
  const w = mount(GroupsManager, withPlugins('en'))
  await flushPromises()
  return w
}

const open = (w, name) => w.find(`[data-group="${name}"] button`).trigger('click')

beforeEach(() => {
  rbacApi.groups.mockResolvedValue({ data: { groups: GROUPS } })
  rbacApi.roles.mockResolvedValue({ data: { roles: [] } })
  rbacApi.members.mockResolvedValue({ data: { members: [] } })
})

// Group members answering out of order (deferred promises)
describe('GroupsManager: answers arriving late', () => {
  it('shows the members of the group opened last', async () => {
    const alpha = deferred()
    rbacApi.group.mockReturnValueOnce(alpha.promise).mockResolvedValueOnce(detailOf('g-b', 'bob'))
    const w = await mountManager()

    await open(w, 'alpha')
    await open(w, 'beta')
    await flushPromises()
    alpha.resolve(detailOf('g-a', 'ann'))
    await flushPromises()

    const beta = w.find('[data-group="beta"]')
    expect(beta.text()).toContain('bob')
    expect(beta.text()).not.toContain('ann')
  })
})

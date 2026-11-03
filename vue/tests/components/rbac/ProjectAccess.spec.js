import { describe, it, expect, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ProjectAccess from '@/components/rbac/ProjectAccess.vue'
import { withPlugins } from '../../helpers'

vi.mock('@/api/rbac', () => ({
  rbacApi: {
    projectAccess: vi.fn(async () => ({ data: { grants: [], can_manage: true } })),
    roles: vi.fn(async () => ({ data: { roles: [{ key: 'developer', name: 'Developer', builtin: true, grantable: true }] } })),
    members: vi.fn(async () => ({ data: { members: [{ id: 7, name: 'Ada', email: 'ada@example.test' }] } })),
    groups: vi.fn(async () => ({ data: { groups: [] } })),
  },
}))

// The project's Access tab (docs/permissions.md): the grant form's four fields sat in one fixed
// row and ran past a phone's screen; on a phone they stack.

describe('ProjectAccess: the grant form', () => {
  it('stacks its fields on a phone and puts them in one row from sm', async () => {
    const w = mount(ProjectAccess, { props: { projectId: 'p1' }, ...withPlugins('en') })
    await flushPromises()
    const form = w.get('[data-testid="access-subject"]').element.closest('.grid')
    expect([...form.classList]).toEqual(expect.arrayContaining(['grid-cols-1', 'sm:grid-cols-[8rem_1fr_12rem_auto]']))
    // the blank label that lines the member picker up with the labelled fields: not on a phone
    const spacer = w.get('[data-testid="access-subject"]').element.previousElementSibling
    expect(spacer.classList.contains('max-sm:hidden')).toBe(true)
    expect(w.get('[data-testid="access-subject"]').findAll('option').map(o => o.text())).toContain('Ada — ada@example.test')
  })
})

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { h } from 'vue'
import { makeI18n, freshPinia } from '@/test-support/helpers'
import { deferred } from '../helpers'

vi.mock('@/api/projects', () => ({ projectsApi: { list: vi.fn() } }))
vi.mock('@/api/settings', () => ({ settingsApi: { get: vi.fn(async () => ({ data: { regions: [] } })) } }))
vi.mock('@/api/runtimes', () => ({ runtimesApi: { list: vi.fn(async () => ({ data: { data: [] } })) } }))
vi.mock('@/api/rbac', () => ({ rbacApi: { myPermissions: vi.fn(async () => ({ data: {} })) } }))
// The header and the cards pull their own stores: stubs keep the list under test alone
vi.mock('@/components/AppHeader.vue', () => ({ default: { name: 'AppHeader', render: () => h('header') } }))
vi.mock('@/components/ProjectCard.vue', () => ({
  default: { name: 'ProjectCard', props: ['project'], render() { return h('div', { 'data-card': this.project.name }) } },
}))

import ProjectsView from '@/views/ProjectsView.vue'
import { projectsApi } from '@/api/projects'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'

const i18n = makeI18n()
const mounted = []
const project = (id, name = id) => ({ id, name, slug: name, service_type: 'xaas', config: {} })
const cards = (w) => w.findAll('[data-card]').map(c => c.attributes('data-card'))

async function mountView() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  await router.push('/projects')
  const w = mount(ProjectsView, {
    global: {
      plugins: [freshPinia(), i18n, router],
      stubs: { ResourceBars: true, RegionFilterDropdown: true, RuntimeFilterDropdown: true, ScalingFilterDropdown: true },
    },
  })
  mounted.push(w)
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.setItem('token', '1|t')
  localStorage.setItem('user', JSON.stringify({ id: 7, tutorial_completed_at: '2026-01-01T00:00:00Z' }))
})
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

// Broadcasts (a project created here, in another tab or by someone else) that arrive while the
// list is loading: the list's answer may have been read before them
describe('Projects page: broadcasts during a list load', () => {
  it('keeps a project created while the list loaded', async () => {
    const list = deferred()
    projectsApi.list.mockReturnValueOnce(list.promise)
    const w = await mountView()
    await flushPromises()

    dispatchPlatformEvent('project.created', { project: { ...project('p-new', 'fresh'), created_by: { id: 99 } } })
    list.resolve({ data: { data: [project('p-1', 'older')] } })
    await flushPromises()

    expect(cards(w)).toEqual(['fresh', 'older'])
  })

  it('keeps a deletion and an update broadcast while the list loaded', async () => {
    projectsApi.list.mockResolvedValueOnce({ data: { data: [project('p-1', 'one'), project('p-2', 'two')] } })
    const w = await mountView()
    await flushPromises()

    const refresh = deferred()
    projectsApi.list.mockReturnValueOnce(refresh.promise)
    dispatchPlatformEvent('demo.seeded', {})        // a full refetch starts
    dispatchPlatformEvent('project.deleted', { project_id: 'p-2' })
    dispatchPlatformEvent('project.updated', { project: { ...project('p-1', 'one-renamed') } })
    refresh.resolve({ data: { data: [project('p-1', 'one'), project('p-2', 'two')] } })
    await flushPromises()

    expect(cards(w)).toEqual(['one-renamed'])
  })

  it('shows the newest of two list answers', async () => {
    const first = deferred()
    projectsApi.list.mockReturnValueOnce(first.promise)
    const w = await mountView()
    await flushPromises()

    projectsApi.list.mockResolvedValueOnce({ data: { data: [project('p-1', 'one'), project('p-3', 'three')] } })
    dispatchPlatformEvent('demo.removed', {})       // a second, newer load
    await flushPromises()
    first.resolve({ data: { data: [project('p-1', 'one')] } })
    await flushPromises()

    expect(cards(w)).toEqual(['one', 'three'])
  })
})

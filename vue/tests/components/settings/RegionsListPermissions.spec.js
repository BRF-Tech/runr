import { describe, it, expect, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import RegionsList from '@/components/settings/RegionsList.vue'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n, freshPinia } from '@/test-support/helpers'

// A synced (platform) region in an organization has one button: it opens the region's demo
// projects, whose Seed / Remove send POST / DELETE knative-regions/{id}/demo-projects
// (regions.manage). A read-only role does not get it.

const i18n = makeI18n()
const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

const synced = { id: 'r1', name: 'eu-1', is_synced: true, is_demo: false, region_demo_status: null }
const own    = { id: 'r2', name: 'lab', is_synced: false, is_demo: false, cluster_status: 'online', project_count: 0, has_kubeconfig: true }

async function mountList(permissions) {
  const pinia = freshPinia()
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'multi', role: null, roles: [], projects: {} })
  const w = mount(RegionsList, { props: { regions: [synced, own] }, global: { plugins: [pinia, i18n] } })
  mounted.push(w)
  await flushPromises()
  return w
}

const rowOf = (w, name) => w.find(`[data-region-row="${name}"]`)

describe('RegionsList permissions', () => {
  it('leaves the demo button of a synced region and the region controls out without regions.manage', async () => {
    const w = await mountList(['regions.view', 'projects.view'])

    expect(rowOf(w, 'eu-1').exists()).toBe(true)
    expect(rowOf(w, 'eu-1').find('[data-action="region-demo"]').exists()).toBe(false)
    expect(rowOf(w, 'eu-1').findAll('button')).toHaveLength(0)
    expect(rowOf(w, 'lab').findAll('button')).toHaveLength(0)
    expect(w.find('[data-tour="settings-add-region"]').exists()).toBe(false)
  })

  it('opens the demo projects of a synced region with regions.manage', async () => {
    const w = await mountList(['regions.view', 'regions.manage'])

    const demo = rowOf(w, 'eu-1').find('[data-action="region-demo"]')
    expect(demo.exists()).toBe(true)
    await demo.trigger('click')
    expect(w.emitted('edit')[0][0].id).toBe('r1')
    expect(w.find('[data-tour="settings-add-region"]').exists()).toBe(true)
  })
})

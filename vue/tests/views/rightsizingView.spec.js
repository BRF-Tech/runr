import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { h } from 'vue'
import RightsizingView from '@/views/RightsizingView.vue'
import { rightsizingApi } from '@/api/rightsizing'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { withPlugins } from '../helpers'

// The header pulls notifications / tutorial stores: a stub keeps the view under test alone
vi.mock('@/components/AppHeader.vue', () => ({ default: { name: 'AppHeader', render: () => h('header') } }))

const row = (name, over = {}) => ({
  id: `r-${name}`, project_id: `p-${name}`, project: { id: `p-${name}`, name, slug: name, service_type: 'faas' },
  status: 'open', direction: 'down',
  current: { cpu_m: 500, memory_mb: 512 }, recommended: { cpu_m: 125, memory_mb: 256 },
  changes: { cpu: 'down', memory: 'down' }, reasons: ['memory_over_provisioned', 'cpu_over_provisioned'],
  evidence: { window_days: 14, memory_max_mb: 140, cpu_p95_m: 80 },
  freed: { cpu_m: 375, memory_mb: 256, pods: 1, always_on: false },
  savings: null, blocked: null,
  ...over,
})

let router
const mounted = []

async function mountView(list, permissions = ['projects.view', 'metrics.view', 'projects.update']) {
  const plugins = withPlugins()
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
  vi.spyOn(rightsizingApi, 'list').mockResolvedValue({ data: list })
  const w = mount(RightsizingView, { global: { plugins: [...plugins.global.plugins, router] } })
  mounted.push(w)
  await flushPromises()
  return w
}

beforeEach(() => {
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
})

afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  vi.restoreAllMocks()
  useToast().toasts.value = []
})

describe('RightsizingView', () => {
  it('lists the open recommendations in the order the API sorts them, with the total', async () => {
    const w = await mountView({
      data: [
        row('billing', { savings: { basis: 'reserved', monthly: 12.5, currency: 'USD' } }),
        row('orders', { savings: { basis: 'usage', monthly: 0, currency: 'USD' } }),
      ],
      summary: { count: 2, monthly_savings: 12.5, currency: 'USD', freed_memory_mb: 512, freed_cpu_m: 750 },
    })

    const rows = w.findAll('[data-rightsizing-row]')
    expect(rows.map(r => r.attributes('data-rightsizing-row'))).toEqual(['billing', 'orders'])
    expect(rows[0].text()).toContain('512 MB / 500m')
    expect(rows[0].text()).toContain('256 MB / 125m')
    expect(rows[0].text()).toContain('memory unused, CPU unused')
    expect(rows[0].find('[data-test="rightsizing-row-savings"]').text()).toBe('about $12.50 less per month')
    expect(rows[1].find('[data-test="rightsizing-row-savings"]').text()).toBe('billed by use: frees capacity, not money')
    expect(w.find('[data-test="rightsizing-summary"]').text()).toBe('2 recommendation(s) · about $12.50 per month in all')
    expect(rows[0].find('a').attributes('href')).toBe('/projects/p-billing')
  })

  it('without billing the total is in resources', async () => {
    const w = await mountView({ data: [row('orders')], summary: { count: 1, monthly_savings: null, currency: null, freed_memory_mb: 256, freed_cpu_m: 375 } })
    expect(w.find('[data-test="rightsizing-summary"]').text()).toBe('1 recommendation(s) · frees 256 MB memory and 375m CPU')
    expect(w.find('[data-test="rightsizing-row-savings"]').text()).toBe('—')
  })

  it('says when there is nothing to do', async () => {
    const w = await mountView({ data: [], summary: { count: 0 } })
    expect(w.find('[data-test="rightsizing-empty"]').exists()).toBe(true)
    expect(w.find('[data-test="rightsizing-table"]').exists()).toBe(false)
  })

  it('applies and dismisses a row', async () => {
    const w = await mountView({ data: [row('billing'), row('orders')], summary: { count: 2 } })
    const apply = vi.spyOn(rightsizingApi, 'apply').mockResolvedValue({ data: { data: row('billing', { status: 'applied' }), version: { label: 'v4' } } })
    const dismiss = vi.spyOn(rightsizingApi, 'dismiss').mockResolvedValue({ data: { data: row('orders', { status: 'snoozed' }) } })

    await w.findAll('[data-test="rightsizing-row-apply"]')[0].trigger('click')
    await flushPromises()
    useConfirm().accept()
    await flushPromises()
    expect(apply).toHaveBeenCalledWith('p-billing')
    expect(useToast().toasts.value.at(-1).message).toBe('Saved as v4. Deploy the project to run the new size.')
    expect(w.findAll('[data-rightsizing-row]').map(r => r.attributes('data-rightsizing-row'))).toEqual(['orders'])

    await w.find('[data-test="rightsizing-row-snooze"]').trigger('click')
    await flushPromises()
    expect(dismiss).toHaveBeenCalledWith('p-orders', 30)
    expect(w.find('[data-test="rightsizing-empty"]').exists()).toBe(true)
  })

  it('shows no buttons to members without projects.update; a blocked row cannot be applied', async () => {
    const viewer = await mountView({ data: [row('orders')], summary: { count: 1 } }, ['projects.view', 'metrics.view'])
    expect(viewer.find('[data-test="rightsizing-row-apply"]').exists()).toBe(false)

    const blocked = await mountView({ data: [row('grow', { blocked: { code: 'resource_limit_exceeded', message: 'No room in eu-1.' } })], summary: { count: 1 } })
    expect(blocked.find('[data-test="rightsizing-row-apply"]').attributes('disabled')).toBeDefined()
    expect(blocked.text()).toContain('Applying it would be refused now: No room in eu-1.')
  })
})

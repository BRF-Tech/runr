import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

// Data residency: r-blocked is not allowed for the organization
vi.mock('@/modules/supply-chain/composables/useResidency', () => ({
  useResidency: () => ({ residency: ref(null), load: vi.fn(), isAllowed: (id) => id !== 'r-blocked' }),
}))

import GlobalRegionPicker from '@/components/project/GlobalRegionPicker.vue'
import { withPlugins } from '../../helpers'

const region = (id, name, extra = {}) => ({ id, name, is_active: true, is_synced: true, global_dns_ready: true, ...extra })

const REGIONS = [
  region('r-eu', 'eu-1'),
  region('r-us', 'us-1'),
  region('r-ap', 'ap-1'),
  region('r-old', 'old-1', { global_dns_ready: false }),
  region('r-blocked', 'cn-1'),
  region('r-demo', 'demo', { is_demo: true }),
]

function mountPicker(props = {}) {
  return mount(GlobalRegionPicker, {
    props: { regions: REGIONS, regionIds: [], primaryId: null, mode: 'ha', ...props },
    ...withPlugins('en'),
  })
}

const regionButton = (w, name) => w.find(`[data-global-region="${name}"]`)

describe('GlobalRegionPicker', () => {
  it('offers only regions with a ready global DNS server and says why the others are greyed out', () => {
    const w = mountPicker()
    expect(regionButton(w, 'eu-1').attributes('disabled')).toBeUndefined()
    expect(regionButton(w, 'old-1').attributes('disabled')).toBeDefined()
    expect(regionButton(w, 'old-1').text()).toContain('No global DNS server')
    expect(regionButton(w, 'cn-1').attributes('disabled')).toBeDefined()
    expect(regionButton(w, 'cn-1').text()).toContain('Not allowed by data residency')
    expect(regionButton(w, 'demo').text()).toContain('Demo regions cannot take part')
    expect(w.find('[data-testid="global-regions-missing"]').text()).toContain('3 not offered')
  })

  it('picks regions, makes the first one the primary and keeps it while others are added', async () => {
    const w = mountPicker()
    await regionButton(w, 'us-1').trigger('click')
    expect(w.emitted('update:regionIds').at(-1)).toEqual([['r-us']])
    expect(w.emitted('update:primaryId').at(-1)).toEqual(['r-us'])

    await w.setProps({ regionIds: ['r-us'], primaryId: 'r-us' })
    expect(w.text()).toContain('Pick at least 2 regions.')
    await regionButton(w, 'eu-1').trigger('click')
    expect(w.emitted('update:regionIds').at(-1)).toEqual([['r-us', 'r-eu']])
    // the primary stays: no new primary emitted
    expect(w.emitted('update:primaryId')).toHaveLength(1)
  })

  it('moves the primary to the next region when the primary is unpicked', async () => {
    const w = mountPicker({ regionIds: ['r-eu', 'r-us'], primaryId: 'r-eu' })
    await regionButton(w, 'eu-1').trigger('click')
    expect(w.emitted('update:regionIds').at(-1)).toEqual([['r-us']])
    expect(w.emitted('update:primaryId').at(-1)).toEqual(['r-us'])
  })

  it('chooses the primary among the picked regions and the DNS mode', async () => {
    const w = mountPicker({ regionIds: ['r-eu', 'r-us'], primaryId: 'r-eu' })
    const options = w.find('[data-testid="global-primary"]').findAll('option').map(o => o.text())
    expect(options).toEqual(['eu-1', 'us-1'])
    await w.find('[data-testid="global-primary"]').setValue('r-us')
    expect(w.emitted('update:primaryId').at(-1)).toEqual(['r-us'])

    expect(w.find('[data-global-mode="ha"]').attributes('aria-pressed')).toBe('true')
    expect(w.text()).toContain('DNS answers the primary region')
    await w.find('[data-global-mode="proximity"]').trigger('click')
    expect(w.emitted('update:mode').at(-1)).toEqual(['proximity'])
  })

  it('stops at the maximum number of regions', () => {
    const many = Array.from({ length: 9 }, (_, i) => region(`r${i}`, `r-${i}`))
    const ids = many.slice(0, 8).map(r => r.id)
    const w = mount(GlobalRegionPicker, {
      props: { regions: many, regionIds: ids, primaryId: 'r0', mode: 'ha' },
      ...withPlugins('en'),
    })
    expect(regionButton(w, 'r-8').attributes('disabled')).toBeDefined()
    expect(regionButton(w, 'r-0').attributes('disabled')).toBeUndefined()
  })
})

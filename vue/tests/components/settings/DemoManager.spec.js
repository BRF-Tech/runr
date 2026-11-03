import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import DemoManager from '@/components/settings/DemoManager.vue'
import { withPlugins } from '../../helpers'

vi.mock('@/api/settings', () => ({ settingsApi: { seedDemo: vi.fn(), removeDemo: vi.fn() } }))
vi.mock('@/composables/useJobEvents', () => ({ useJobEvents: () => {} }))

// Loading and removing the demo data (POST / DELETE /demo) need settings.manage: a read-only role
// (the public demo's) saw Remove and got a 403. Without the permission the status shows alone.

const mounted = []
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

function mountDemo(props) {
  const w = mount(DemoManager, { props, ...withPlugins('en') })
  mounted.push(w)
  return w
}

describe('DemoManager', () => {
  it('offers Remove for loaded demo data and Load otherwise, with the permission', () => {
    expect(mountDemo({ status: 'loaded' }).find('[data-testid="demo-remove"]').exists()).toBe(true)
    expect(mountDemo({ status: 'idle' }).find('[data-testid="demo-seed"]').exists()).toBe(true)
  })

  it('shows only the status without it', () => {
    const loaded = mountDemo({ status: 'loaded', canManage: false })
    expect(loaded.find('[data-testid="demo-remove"]').exists()).toBe(false)
    expect(loaded.find('[data-testid="demo-seed"]').exists()).toBe(false)
    expect(loaded.text()).toContain('Demo data loaded')
    // the tour's demo step still has its card to point at
    expect(loaded.find('[data-tour="settings-demo"]').exists()).toBe(true)

    const idle = mountDemo({ status: 'idle', canManage: false })
    expect(idle.find('button').exists()).toBe(false)
  })
})

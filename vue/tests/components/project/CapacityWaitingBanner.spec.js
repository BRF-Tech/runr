import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import CapacityWaitingBanner from '@/components/project/CapacityWaitingBanner.vue'
import { makeI18n } from '@/test-support/helpers'

// A resumed project that waits for capacity (project.capacity_waiting, StoppedCapacity::waiting):
// the project page says why — the region, the organization's quota, a fixed region's approved
// resources — what each short resource lacks and what to do; a project that fits again says so.

const i18n = makeI18n()
const mounted = []

function mountBanner(waiting, region = { name: 'eu-1' }) {
  const wrapper = mount(CapacityWaitingBanner, {
    props: { project: { id: 'p1', region, capacity_waiting: waiting } },
    global: { plugins: [i18n] },
  })
  mounted.push(wrapper)
  return wrapper
}

afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('CapacityWaitingBanner', () => {
  it('is not there while the project does not wait', () => {
    expect(mountBanner(null).find('[data-testid="capacity-waiting"]').exists()).toBe(false)
  })

  it('says the region has no room, what is missing and what to do', () => {
    const w = mountBanner({
      since: '2026-11-11T09:00:00+00:00', code: 'capacity_exceeded', message: 'Region eu-1 has no room for this project again…', region_id: 'r1',
      shortfall: { cpu_m: { budget: 'faas', needed: 350, available: 200, missing: 150 } },
    })

    expect(w.find('[data-tour="capacity-waiting-banner"]').exists()).toBe(true)
    expect(w.text()).toContain('This project waits for capacity')
    expect(w.find('[data-testid="capacity-waiting-why"]').text()).toContain('Its stop gave its capacity in region eu-1 back')
    expect(w.find('[data-testid="capacity-waiting-shortfall"]').text()).toBe('CPU: needs 350m, 200m free — 150m missing')
    expect(w.find('[data-testid="capacity-waiting-todo"]').text()).toContain('ask the region\'s provider to add nodes')
    expect(w.text()).toContain('Waiting since')
  })

  it('names the organization quota and a fixed region\'s approved resources', () => {
    let w = mountBanner({ code: 'quota_exceeded', shortfall: { memory_mb: { needed: 612, available: 100, missing: 512 } } })
    expect(w.find('[data-testid="capacity-waiting-why"]').text()).toContain('share of the organization\'s quota in region eu-1')
    expect(w.find('[data-testid="capacity-waiting-shortfall"]').text()).toBe('Memory: needs 612 MB, 100 MB free — 512 MB missing')
    expect(w.find('[data-testid="capacity-waiting-todo"]').text()).toContain('ask the platform for a larger quota')

    w = mountBanner({ code: 'fixed_allocation_exceeded', shortfall: { cpu_m: { needed: 1200, available: 0, missing: 1200 }, gpus: { needed: 1, available: 0, missing: 1 } } })
    expect(w.find('[data-testid="capacity-waiting-why"]').text()).toContain('took over its approved resources')
    expect(w.find('[data-testid="capacity-waiting-shortfall"]').text()).toContain('GPUs: needs 1 GPU, 0 GPU free — 1 GPU missing')
    expect(w.find('[data-testid="capacity-waiting-todo"]').text()).toContain('request more resources on the Billing tab')
  })

  it('says when it fits again, and shows another guard\'s reason as it came', () => {
    let w = mountBanner({ code: null, shortfall: null })
    expect(w.text()).toContain('This project can run again')
    expect(w.find('[data-testid="capacity-waiting-shortfall"]').exists()).toBe(false)
    expect(w.find('[data-testid="capacity-waiting-todo"]').text()).toBe('Deploy a version to run it again.')

    w = mountBanner({ code: 'fixed_allocation_required', message: 'Request resources first.' })
    expect(w.find('[data-testid="capacity-waiting-why"]').text()).toBe('Request resources first.')
  })
})

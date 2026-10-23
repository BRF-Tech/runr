import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GeneralTab from '@/components/region/GeneralTab.vue'
import { makeI18n, freshPinia } from '@/test-support/helpers'

// The region's General tab shows what the last region:reconcile left it with (the region API's
// reconcile: the upgrade's Job runr-region-reconcile-<revision>, which helm upgrade does not wait
// for): while it runs, and when it left the region behind the release — not when it is done.

const i18n = makeI18n()

function mountTab(reconcile) {
  return mount(GeneralTab, {
    props: {
      isEdit: true,
      form: { name: 'eu-1', region_status: 'active' },
      region: { id: 'r1', name: 'eu-1', has_serving: false, reconcile },
    },
    global: { plugins: [freshPinia(), i18n] },
  })
}

describe('GeneralTab — region:reconcile', () => {
  it('says the region is not at the release and names what was left', () => {
    const wrapper = mountTab({
      status: 'unfinished', version: 'v0.6.0', run: 'helm-7',
      problems: ['[private-ingress] failed: helm upgrade failed', 'not run: cert-manager (the deadline passed)'],
    })
    const box = wrapper.get('[data-testid="region-reconcile"]')
    expect(box.text()).toContain('not at RunR v0.6.0 yet')
    expect(box.text()).toContain('[private-ingress] failed: helm upgrade failed')
    expect(box.text()).toContain('not run: cert-manager (the deadline passed)')
    expect(box.text()).toContain('php artisan region:reconcile')
  })

  it('says a cluster that did not answer, and a run that goes on', () => {
    expect(mountTab({ status: 'unreachable', version: 'v0.6.0', problems: ['unreachable'] }).get('[data-testid="region-reconcile"]').text())
      .toContain('could not reach the cluster of this region')
    const running = mountTab({ status: 'running', version: 'v0.6.0', problems: [] }).get('[data-testid="region-reconcile"]')
    expect(running.text()).toContain('being brought to RunR v0.6.0')
    expect(running.text()).not.toContain('php artisan region:reconcile')
  })

  it('says nothing when the region is done or never ran', () => {
    expect(mountTab({ status: 'done', version: 'v0.6.0', problems: [] }).find('[data-testid="region-reconcile"]').exists()).toBe(false)
    expect(mountTab(null).find('[data-testid="region-reconcile"]').exists()).toBe(false)
    expect(mountTab(undefined).find('[data-testid="region-reconcile"]').exists()).toBe(false)
  })
})

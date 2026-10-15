import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PolicyBlockModal from '@/modules/supply-chain/components/PolicyBlockModal.vue'
import { makeScI18n, report } from './support'

const i18n = makeScI18n()
const mountModal = (block) => mount(PolicyBlockModal, { props: { block }, global: { plugins: [i18n] } })

const blocked = (extra = {}) => ({
  message:      'Deploy blocked by the supply-chain policy of region istanbul: 2 CRITICAL vulnerabilities (allowed: 0).',
  code:         'supply_chain_policy',
  violations:   [{ rule: 'critical', message: '2 CRITICAL vulnerabilities (allowed: 0)', enforced: true }],
  can_override: true,
  report:       report(),
  versionId:    'v1',
  ...extra,
})

describe('PolicyBlockModal', () => {
  it('shows the message, the counts and the violated rules', () => {
    const w = mountModal(blocked())

    expect(w.text()).toContain('Deploy blocked')
    expect(w.find('[data-block-message]').text()).toContain('region istanbul')
    expect(w.find('[data-violation="critical"]').text()).toBe('2 CRITICAL vulnerabilities (allowed: 0)')
    expect(w.find('[data-severity="critical"]').text()).toContain('2')
  })

  it('overrides only with a reason', async () => {
    const w = mountModal(blocked())

    expect(w.find('[data-override]').attributes('disabled')).toBeDefined()
    await w.find('[data-override-reason]').setValue('  ok  ')
    await w.find('[data-override]').trigger('click')
    expect(w.emitted('override')).toBeUndefined()

    await w.find('[data-override-reason]').setValue('  CVE fixed upstream, rollout tonight ')
    await w.find('[data-override]').trigger('click')
    expect(w.emitted('override')).toEqual([['CVE fixed upstream, rollout tonight']])
  })

  it('without the permission there is no override', () => {
    const w = mountModal(blocked({ can_override: false }))

    expect(w.find('[data-override]').exists()).toBe(false)
    expect(w.find('[data-override-reason]').exists()).toBe(false)
    expect(w.text()).toContain('Overriding the policy needs the corresponding permission.')
  })

  it('a running scan: try again instead of overriding', async () => {
    const w = mountModal(blocked({ code: 'supply_chain_scan_pending', can_override: true, violations: [{ rule: 'scan_pending', message: 'the vulnerability scan is running', enforced: true }] }))

    expect(w.text()).toContain('Security checks running')
    expect(w.find('[data-override]').exists()).toBe(false)
    await w.find('[data-retry]').trigger('click')
    expect(w.emitted('retry')).toHaveLength(1)
  })

  it('a data residency refusal', () => {
    const w = mountModal({ message: 'Your organization keeps its data in istanbul — region frankfurt cannot be used for deployments.', code: 'region_not_allowed', versionId: 'v1' })

    expect(w.text()).toContain('region frankfurt cannot be used')
    expect(w.text()).not.toContain('needs the corresponding permission')
  })
})

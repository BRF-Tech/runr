import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SeverityBadges from '@/modules/supply-chain/components/SeverityBadges.vue'
import { makeScI18n } from './support'

const i18n = makeScI18n()
const mountBadges = (props) => mount(SeverityBadges, { props, global: { plugins: [i18n] } })

describe('SeverityBadges', () => {
  it('shows every severity with its count', () => {
    const w = mountBadges({ counts: { critical: 2, high: 1, medium: 0, low: 3, unknown: 0 } })

    const badges = w.findAll('[data-severity]')
    expect(badges.map(b => b.attributes('data-severity'))).toEqual(['critical', 'high', 'medium', 'low', 'unknown'])
    expect(w.find('[data-severity="critical"]').text()).toBe('Critical2')
    expect(w.find('[data-severity="critical"]').classes()).toContain('text-red-300')
    expect(w.find('[data-severity="medium"]').classes()).toContain('text-gray-500')
  })

  it('compact mode: short labels, critical / high / medium only', () => {
    const w = mountBadges({ counts: { critical: 2, high: 1, medium: 4, low: 3 }, compact: true })

    expect(w.findAll('[data-severity]')).toHaveLength(3)
    expect(w.find('[data-severity="high"]').text()).toBe('H1')
    expect(w.find('[data-severity="critical"]').attributes('title')).toBe('Critical')
  })

  it('compact with all levels', () => {
    expect(mountBadges({ counts: { low: 1 }, compact: true, all: true }).findAll('[data-severity]')).toHaveLength(5)
  })

  it('a dash without counts', () => {
    const w = mountBadges({ counts: null })

    expect(w.find('[data-severity-badges]').exists()).toBe(false)
    expect(w.text()).toBe('—')
  })
})

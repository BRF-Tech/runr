import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FindingsTable from '@/modules/supply-chain/components/FindingsTable.vue'
import { makeScI18n, findings } from './support'

const i18n = makeScI18n()
const mountTable = (props) => mount(FindingsTable, { props, global: { plugins: [i18n] } })

describe('FindingsTable', () => {
  it('lists package, installed and fixed version with an advisory link', () => {
    const w = mountTable({ findings, total: 3 })

    const row = w.find('[data-finding="CVE-2024-45491"]')
    expect(row.text()).toContain('libexpat1')
    expect(row.text()).toContain('2.5.0-1')
    expect(row.text()).toContain('2.5.0-1+deb12u1')
    const link = row.find('a')
    expect(link.attributes('href')).toBe('https://nvd.nist.gov/vuln/detail/CVE-2024-45491')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.attributes('rel')).toContain('noopener')

    expect(w.find('[data-finding="CVE-2023-45853"]').text()).toContain('no fix')
    expect(w.text()).toContain('Showing 3 of 3')
  })

  it('never renders a non-https advisory URL as a link', () => {
    const w = mountTable({ findings })

    expect(w.find('[data-finding="GHSA-9wx4-h78v-vm56"] a').exists()).toBe(false)
    expect(w.find('[data-finding="GHSA-9wx4-h78v-vm56"]').text()).toContain('GHSA-9wx4-h78v-vm56')
  })

  it('filters by severity, text and fixable', async () => {
    const w = mountTable({ findings })

    await w.find('[data-filter-severity]').setValue('CRITICAL')
    expect(w.findAll('[data-finding]')).toHaveLength(2)

    await w.find('[data-filter-fixable]').setValue(true)
    expect(w.findAll('[data-finding]').map(r => r.attributes('data-finding'))).toEqual(['CVE-2024-45491'])

    await w.find('[data-filter-severity]').setValue('')
    await w.find('[data-filter-search]').setValue('requests')
    expect(w.findAll('[data-finding]').map(r => r.attributes('data-finding'))).toEqual(['GHSA-9wx4-h78v-vm56'])

    await w.find('[data-filter-search]').setValue('nothing-like-this')
    expect(w.text()).toContain('No finding matches the filter.')
  })

  it('says when the stored list is shorter than the total', () => {
    const w = mountTable({ findings, total: 2500 })

    expect(w.text()).toContain('2500 findings in total; the list keeps the 3 most severe.')
  })

  it('an image without findings', () => {
    expect(mountTable({ findings: [] }).text()).toContain('No vulnerabilities found.')
  })
})

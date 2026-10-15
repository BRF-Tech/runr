import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TraceWaterfall from '@/modules/observability/components/TraceWaterfall.vue'
import { makeI18n, trace } from './fixtures'

const mountWaterfall = (t = trace()) => mount(TraceWaterfall, { props: { trace: t }, global: { plugins: [makeI18n()] } })

describe('TraceWaterfall', () => {
  it('renders a row per span, indented by depth, with bars at their offsets', () => {
    const w = mountWaterfall()
    const rows = w.findAll('[data-span]')

    expect(rows.map((r) => r.attributes('data-span'))).toEqual(['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc'])
    expect(rows[0].text()).toContain('activator_proxy')
    expect(rows[1].text()).toContain('GET')
    expect(rows[2].text()).toContain('SELECT orders')

    const indent = rows.map((r) => r.find('div').attributes('style'))
    expect(indent[0]).toContain('padding-left: 0px')
    expect(indent[1]).toContain('padding-left: 14px')
    expect(indent[2]).toContain('padding-left: 28px')

    const bar = rows[1].find('[data-test="span-bar"]').attributes('style')
    expect(bar).toContain('left: 10%')
    expect(bar).toContain('width: 80%')
    expect(rows[1].text()).toContain('40 ms')
  })

  it('marks errors', () => {
    const w = mountWaterfall()
    const rows = w.findAll('[data-span]')
    expect(rows[1].attributes('data-error')).toBe('true')
    expect(rows[1].find('[data-test="span-error"]').exists()).toBe(true)
    expect(rows[0].find('[data-test="span-error"]').exists()).toBe(false)
  })

  it('opens and closes the span details: attributes, events, error, resource', async () => {
    const w = mountWaterfall()
    expect(w.find('[data-test="span-details"]').exists()).toBe(false)

    await w.find('[data-span="bbbbbbbbbbbbbbbb"]').trigger('click')
    const details = w.find('[data-test="span-details"]')
    expect(details.exists()).toBe(true)
    expect(details.text()).toContain('http.response.status_code')
    expect(details.text()).toContain('500')
    expect(details.text()).toContain('url.path')
    expect(details.find('[data-test="span-status-error"]').text()).toContain('HTTP 500')
    expect(details.find('[data-test="span-events"]').text()).toContain('exception')
    expect(details.find('[data-test="span-events"]').text()).toContain('database unreachable')
    expect(details.text()).toContain('runr.project_id')

    await w.find('[data-span="bbbbbbbbbbbbbbbb"]').trigger('click')
    expect(w.find('[data-test="span-details"]').exists()).toBe(false)
  })

  it('shows a span without attributes', async () => {
    const w = mountWaterfall()
    await w.find('[data-span="aaaaaaaaaaaaaaaa"]').trigger('click')
    expect(w.find('[data-test="span-details"]').text()).toContain('No attributes.')
    expect(w.find('[data-test="span-events"]').exists()).toBe(false)
  })

  it('draws the time axis of the trace', () => {
    const w = mountWaterfall()
    const axis = w.text()
    expect(axis).toContain('Spans: 3')
    expect(axis).toContain('25 ms')
    expect(axis).toContain('50 ms')
  })
})

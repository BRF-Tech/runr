import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AlertRuleEditor from '@/modules/observability/components/AlertRuleEditor.vue'
import { channels, makeI18n, projects, regions } from './fixtures'

const mountEditor = (props = {}) => mount(AlertRuleEditor, {
  props: { channels, projects, regions, ...props },
  global: { plugins: [makeI18n()] },
})

describe('AlertRuleEditor', () => {
  it('creates an uptime rule for a project, routed to the chosen channels', async () => {
    const w = mountEditor()
    await w.find('[data-test="rule-name"]').setValue('API down')
    await w.find('[data-test="rule-project"]').setValue(projects[0].id)
    await w.find('[data-test="rule-severity"]').setValue('critical')
    await w.find(`[data-test="rule-channel-${channels[0].id}"]`).setValue(true)
    await w.find('[data-test="rule-editor"]').trigger('submit')

    expect(w.emitted('submit')[0][0]).toEqual({
      name: 'API down',
      type: 'uptime_down',
      severity: 'critical',
      cooldown_minutes: 15,
      project_id: projects[0].id,
      region_id: null,
      params: {},
      channel_ids: [channels[0].id],
      is_enabled: true,
    })
  })

  it('shows the parameters of each type and sends them as numbers', async () => {
    const w = mountEditor()
    await w.find('[data-test="rule-type"]').setValue('p95_latency')
    expect(w.find('[data-test="param-threshold_ms"]').exists()).toBe(true)
    expect(w.find('[data-test="param-threshold"]').exists()).toBe(false)
    expect(w.find('[data-test="rule-type-hint"]').text()).toContain('95th percentile')

    await w.find('[data-test="rule-name"]').setValue('slow')
    await w.find('[data-test="param-threshold_ms"]').setValue('750')
    await w.find('[data-test="param-window"]').setValue('10')
    await w.find('[data-test="rule-editor"]').trigger('submit')

    const payload = w.emitted('submit')[0][0]
    expect(payload.type).toBe('p95_latency')
    expect(payload.params).toEqual({ threshold_ms: 750, window_minutes: 10, min_requests: 20 })
  })

  it('switching the type resets the parameters to that type\'s defaults', async () => {
    const w = mountEditor()
    await w.find('[data-test="rule-type"]').setValue('error_rate')
    await w.find('[data-test="param-threshold"]').setValue('9')
    await w.find('[data-test="rule-type"]').setValue('cold_start_rate')
    expect(w.find('[data-test="param-threshold"]').element.value).toBe('20')
  })

  it('a resource rule is scoped by region, not by project', async () => {
    const w = mountEditor()
    await w.find('[data-test="rule-type"]').setValue('resource_threshold')
    expect(w.find('[data-test="rule-project"]').exists()).toBe(false)

    await w.find('[data-test="rule-name"]').setValue('memory')
    await w.find('[data-test="param-resource"]').setValue('memory')
    await w.find('[data-test="rule-region"]').setValue(regions[0].id)
    await w.find('[data-test="rule-editor"]').trigger('submit')

    const payload = w.emitted('submit')[0][0]
    expect(payload.project_id).toBeNull()
    expect(payload.region_id).toBe(regions[0].id)
    expect(payload.params).toEqual({ resource: 'memory', min_level: 'warning' })
  })

  it('editing keeps the type fixed and does not send it', async () => {
    const rule = {
      id: 'r1', name: 'errors', type: 'error_rate', severity: 'warning', cooldown_minutes: 30,
      project_id: null, region_id: null, params: { threshold: 7, window_minutes: 5, min_requests: 10 },
      channel_ids: [channels[1].id], is_enabled: false,
    }
    const w = mountEditor({ rule })
    expect(w.find('[data-test="rule-type"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test="param-threshold"]').element.value).toBe('7')

    await w.find(`[data-test="rule-channel-${channels[1].id}"]`).setValue(false)
    await w.find('[data-test="rule-editor"]').trigger('submit')
    const payload = w.emitted('submit')[0][0]
    expect(payload).not.toHaveProperty('type')
    expect(payload.channel_ids).toEqual([])
    expect(payload.cooldown_minutes).toBe(30)
    expect(payload.is_enabled).toBe(false)
  })

  it('without channels says so; shows API validation errors', () => {
    const w = mountEditor({ channels: [], errors: { 'params.threshold': ['The threshold field is required.'], channel_ids: ['Unknown alert channel.'] } })
    expect(w.find('[data-test="rule-no-channels"]').exists()).toBe(true)
    expect(w.text()).toContain('Unknown alert channel.')
  })

  it('cancel', async () => {
    const w = mountEditor()
    await w.findAll('button').find((b) => b.text() === 'Cancel').trigger('click')
    expect(w.emitted('cancel')).toHaveLength(1)
  })
})

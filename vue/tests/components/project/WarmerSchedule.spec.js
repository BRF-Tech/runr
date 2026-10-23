import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, reactive } from 'vue'
import ScaleConfig from '@/components/project/ScaleConfig.vue'
import WarmerSchedule from '@/components/project/WarmerSchedule.vue'
import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import { withPlugins } from '../../helpers'
import { apiError } from '@/test-support/helpers'

vi.mock('@/api/projects', () => ({ projectsApi: { scaleSchedulePreview: vi.fn() } }))

const mounted = []

function week(fill = 0) {
  return Array.from({ length: 7 }, () => Array(24).fill(fill))
}

function previewAnswer(overrides = {}) {
  const timeline = week()
  for (let d = 0; d < 5; d++) for (let hour = 9; hour < 18; hour++) timeline[d][hour] = 3
  return {
    data: {
      timezone: 'Europe/Istanbul', week_start: '2026-10-19T00:00:00+03:00', now: 3, peak: 3, timeline,
      estimate: { billing_type: 'payg', days: 30, container_hours: 579, vcpu_hours: 144.8, memory_gb_hours: 144.8, cost: 6.52, currency: 'USD' },
      ...overrides,
    },
  }
}

/** ScaleConfig as the project forms use it: v-model on the scale fields and the warmer's rows. */
function host(initial = {}, locale = 'en', timezone = null) {
  const plugins = withPlugins(locale)
  // the organization zone of GET /settings (the store of this mount's Pinia)
  if (timezone) useSettingsStore().data.timezone = timezone
  const Host = defineComponent({
    setup() {
      const config = reactive({
        scale_to_zero: true, min_scale: 0, max_scale: 5, scale_schedule: null, cpu: 250, memory_mb: 256, trigger_type: 'http',
        ...initial,
      })
      return { config }
    },
    render() {
      const c = this.config
      return h(ScaleConfig, {
        warmer: true,
        scaleToZero: c.scale_to_zero, 'onUpdate:scaleToZero': v => { c.scale_to_zero = v },
        minScale: c.min_scale, 'onUpdate:minScale': v => { c.min_scale = v },
        maxScale: c.max_scale, 'onUpdate:maxScale': v => { c.max_scale = v },
        scaleSchedule: c.scale_schedule, 'onUpdate:scaleSchedule': v => { c.scale_schedule = v },
        regionId: 'r1', cpu: c.cpu, memoryMb: c.memory_mb, triggerType: c.trigger_type,
        warmScale: c.warm_scale ?? null,
      })
    },
  })
  const w = mount(Host, { global: plugins.global })
  mounted.push(w)
  return w
}

beforeEach(() => {
  // flushPromises keeps its real setImmediate
  vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
  vi.clearAllMocks()
  projectsApi.scaleSchedulePreview.mockResolvedValue(previewAnswer())
})
afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  vi.useRealTimers()
})

async function settle() {
  await vi.advanceTimersByTimeAsync(450)
  await flushPromises()
}

describe('ScaleConfig: timed warmer toggle', () => {
  it('is offered only where the form asks for it (not for model services)', () => {
    const plain = mount(ScaleConfig, { props: { scaleToZero: true, minScale: 0, maxScale: 1 }, global: withPlugins('en').global })
    mounted.push(plain)
    expect(plain.find('[data-testid="warmer-toggle"]').exists()).toBe(false)
    expect(host().find('[data-testid="warmer-toggle"]').exists()).toBe(true)
  })

  it('switching it on adds weekday morning / evening rows; off sends null', async () => {
    const w = host()
    await w.find('[data-testid="warmer-toggle"]').trigger('click')
    expect(w.vm.config.scale_schedule).toEqual([{ cron: '0 9 * * 1-5', min_scale: 1 }, { cron: '0 18 * * 1-5', min_scale: 0 }])
    expect(w.findAll('[data-testid="warmer-row"]')).toHaveLength(2)
    expect(w.find('[data-testid="warmer-toggle"]').attributes('aria-checked')).toBe('true')

    await w.find('[data-testid="warmer-toggle"]').trigger('click')
    expect(w.vm.config.scale_schedule).toBeNull()
    expect(w.find('[data-testid="warmer-schedule"]').exists()).toBe(false)
  })

  it('replaces the min input in custom mode while it is on', async () => {
    const w = host({ scale_to_zero: false, min_scale: 2, max_scale: 5, scale_schedule: [{ cron: '0 9 * * *', min_scale: 2 }] })
    expect(w.find('[data-testid="scale-min"]').exists()).toBe(false)
    expect(w.find('[data-testid="scale-min-replaced"]').exists()).toBe(true)
  })
})

describe('WarmerSchedule rows', () => {
  it('explains each row with cronstrue, the organization zone and the count', async () => {
    projectsApi.scaleSchedulePreview.mockResolvedValue(previewAnswer({ timezone: 'Europe/Berlin' }))
    const w = host({ scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 3 }, { cron: '0 18 * * 1-5', min_scale: 0 }, { cron: '0 12 * * 6', min_scale: 1 }] }, 'en', 'Europe/Berlin')
    const texts = w.findAll('[data-testid="warmer-explain"]').map(p => p.text())
    expect(texts).toEqual([
      'At 09:00, Monday through Friday (Europe/Berlin): from then on 3 containers stay warm',
      'At 18:00, Monday through Friday (Europe/Berlin): from then on nothing is kept warm',
      'At 12:00, only on Saturday (Europe/Berlin): from then on 1 container stays warm',
    ])
  })

  it('explains in Turkish with the tr locale', () => {
    const w = host({ scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 3 }] }, 'tr')
    expect(w.find('[data-testid="warmer-explain"]').text())
      .toBe('Saat 09:00, Pazartesi ile Cuma arasında (Europe/Istanbul): bu andan itibaren 3 konteyner sıcak tutulur')
  })

  it('shows a local error instead of an explanation for an unreadable cron or a count above the max', async () => {
    const w = host({ max_scale: 2, scale_schedule: [{ cron: '61 9 * * *', min_scale: 1 }, { cron: '0 9 * * *', min_scale: 3 }] })
    const errors = w.findAll('[data-testid="warmer-row-error"]').map(p => p.text())
    expect(errors[0]).toMatch(/^Use 5 fields/)
    expect(errors[1]).toBe('At most 2 (the max scale, never above 10)')
    expect(w.findAll('[data-testid="warmer-explain"]')).toHaveLength(0)

    // nothing invalid is sent for a preview
    await settle()
    expect(projectsApi.scaleSchedulePreview).not.toHaveBeenCalled()
  })

  it('edits, adds (at most 10) and removes rows', async () => {
    const w = host({ scale_schedule: [{ cron: '0 9 * * *', min_scale: 1 }] })
    await w.find('[data-testid="warmer-cron"]').setValue('0 8 * * 1-5')
    await w.find('[data-testid="warmer-count"]').setValue('4')
    expect(w.vm.config.scale_schedule).toEqual([{ cron: '0 8 * * 1-5', min_scale: 4 }])

    await w.find('[data-testid="warmer-add"]').trigger('click')
    expect(w.vm.config.scale_schedule).toHaveLength(2)
    expect(w.vm.config.scale_schedule[1]).toEqual({ cron: '0 0 * * *', min_scale: 0 })

    await w.findAll('[data-testid="warmer-remove"]')[0].trigger('click')
    expect(w.vm.config.scale_schedule).toEqual([{ cron: '0 0 * * *', min_scale: 0 }])

    w.vm.config.scale_schedule = Array.from({ length: 10 }, () => ({ cron: '0 9 * * *', min_scale: 1 }))
    await flushPromises()
    expect(w.find('[data-testid="warmer-add"]').attributes('disabled')).toBeDefined()
  })
})

describe('WarmerSchedule preview', () => {
  it('asks once the typing stopped, with the rows, the size and the region', async () => {
    const w = host({ scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 3 }, { cron: '0 18 * * 1-5', min_scale: 0 }] })
    await w.find('[data-testid="warmer-count"]').setValue('2')
    await w.find('[data-testid="warmer-count"]').setValue('3')
    expect(projectsApi.scaleSchedulePreview).not.toHaveBeenCalled()

    await settle()
    expect(projectsApi.scaleSchedulePreview).toHaveBeenCalledTimes(1)
    expect(projectsApi.scaleSchedulePreview).toHaveBeenCalledWith({
      scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 3 }, { cron: '0 18 * * 1-5', min_scale: 0 }],
      knative_region_id: 'r1', cpu: '250m', memory_mb: 256, scale_to_zero: true, max_scale: 5, trigger_type: 'http',
    })
  })

  it('draws the week (Monday first, 7 × 24) with the value of every hour', async () => {
    const w = host({ scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 3 }, { cron: '0 18 * * 1-5', min_scale: 0 }] })
    await settle()
    const cells = w.findAll('[data-testid="warmer-cell"]')
    expect(cells).toHaveLength(7 * 24)
    expect(cells[9].attributes('data-value')).toBe('3')
    expect(cells[9].attributes('title')).toBe('Mon 09:00–10:00: 3')
    expect(cells[8].attributes('data-value')).toBe('0')
    expect(cells[5 * 24 + 9].attributes('data-value')).toBe('0')
    expect(w.find('[data-testid="warmer-timeline"]').text()).toContain('Warm containers this week (Europe/Istanbul)')
    expect(w.find('[data-testid="warmer-now"]').text()).toBe('Now: 3 warm')
  })

  it('shows the month\'s warm hours and cost in pay-as-you-go regions, nothing without an estimate', async () => {
    const w = host({ scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 3 }] })
    await settle()
    const estimate = w.find('[data-testid="warmer-estimate"]')
    expect(estimate.text()).toContain('≈ 144.8 warm vCPU-hours and 144.8 GB-hours over 30 days')
    expect(w.find('[data-testid="warmer-cost"]').text()).toBe('≈ $6.52 for the warm containers')

    projectsApi.scaleSchedulePreview.mockResolvedValue(previewAnswer({ estimate: { billing_type: 'payg', days: 30, container_hours: 1, vcpu_hours: 0.3, memory_gb_hours: 0.3, cost: null, currency: null } }))
    await w.find('[data-testid="warmer-count"]').setValue('1')
    await settle()
    expect(w.find('[data-testid="warmer-estimate"]').exists()).toBe(true)
    expect(w.find('[data-testid="warmer-cost"]').exists()).toBe(false)

    projectsApi.scaleSchedulePreview.mockResolvedValue(previewAnswer({ estimate: null }))
    await w.find('[data-testid="warmer-count"]').setValue('2')
    await settle()
    expect(w.find('[data-testid="warmer-estimate"]').exists()).toBe(false)
  })

  it('puts the API\'s 422 errors under their rows', async () => {
    projectsApi.scaleSchedulePreview.mockRejectedValue(apiError(422, {
      message: 'invalid', errors: { 'scale_schedule.1.min_scale': ['The count may not be greater than the max scale.'] },
    }))
    const w = host({ scale_schedule: [{ cron: '0 9 * * *', min_scale: 1 }, { cron: '0 18 * * *', min_scale: 0 }] })
    await settle()
    const rows = w.findAll('[data-testid="warmer-row"]')
    expect(rows[0].find('[data-testid="warmer-row-error"]').exists()).toBe(false)
    expect(rows[1].find('[data-testid="warmer-row-error"]').text()).toBe('The count may not be greater than the max scale.')
  })

  it('says when the preview is unavailable and keeps the editor usable', async () => {
    projectsApi.scaleSchedulePreview.mockRejectedValue(apiError(500))
    const w = host({ scale_schedule: [{ cron: '0 9 * * *', min_scale: 1 }] })
    await settle()
    expect(w.text()).toContain('The week preview is not available right now.')
    expect(w.find('[data-testid="warmer-explain"]').exists()).toBe(true)
  })

  it('shows what the scheduler applied last', () => {
    const w = host({ scale_schedule: [{ cron: '0 9 * * *', min_scale: 3 }], warm_scale: { applied: 3, applied_at: '2026-10-21T06:00:00Z' } })
    expect(w.find('[data-testid="warmer-applied"]').text()).toMatch(/^Warm now: 3 containers \(applied .+\)$/)
  })
})

describe('WarmerSchedule alone', () => {
  it('reads the zone from the settings store before the first preview', () => {
    const plugins = withPlugins('en')
    useSettingsStore().data.timezone = 'Asia/Tokyo'
    const w = mount(WarmerSchedule, { props: { rows: [{ cron: '0 9 * * *', min_scale: 2 }], maxScale: 4 }, global: plugins.global })
    mounted.push(w)
    expect(w.find('[data-testid="warmer-explain"]').text()).toContain('(Asia/Tokyo)')
    expect(w.text()).toContain('Times are in Asia/Tokyo')
  })
})

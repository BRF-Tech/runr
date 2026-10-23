import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import TimezoneCard from '@/components/settings/TimezoneCard.vue'
import { settingsApi } from '@/api/settings'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n, freshPinia, apiError } from '@/test-support/helpers'

vi.mock('@/api/settings', () => ({
  settingsApi: { get: vi.fn(), update: vi.fn(), seedDemo: vi.fn(), removeDemo: vi.fn() },
}))

const i18n = makeI18n()
let pinia
const mounted = []

function mountCard({ timezone = 'Europe/Istanbul', permissions = ['settings.manage'] } = {}) {
  useSettingsStore().data.timezone = timezone
  usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single' })
  const w = mount(TimezoneCard, { global: { plugins: [pinia, i18n] } })
  mounted.push(w)
  return w
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('TimezoneCard (organization time zone)', () => {
  it('shows the effective zone and saves a new one with PATCH /settings { timezone }', async () => {
    settingsApi.update.mockResolvedValue({ data: { base_domain: 'runr.test', timezone: 'Europe/Berlin' } })
    const w = mountCard()
    const input = w.find('[data-testid="timezone-input"]')
    expect(input.element.value).toBe('Europe/Istanbul')
    expect(w.find('[data-testid="timezone-save"]').attributes('disabled')).toBeDefined()

    await input.setValue('Europe/Berlin')
    await w.find('[data-testid="timezone-save"]').trigger('click')
    await flushPromises()

    expect(settingsApi.update).toHaveBeenCalledWith({ timezone: 'Europe/Berlin' })
    expect(useSettingsStore().data.timezone).toBe('Europe/Berlin')
    expect(input.element.value).toBe('Europe/Berlin')
  })

  it('goes back to the default by sending null', async () => {
    settingsApi.update.mockResolvedValue({ data: { timezone: 'Europe/Istanbul' } })
    const w = mountCard({ timezone: 'America/New_York' })
    const reset = w.findAll('button').find(b => b.text() === 'Back to Europe/Istanbul')
    expect(reset).toBeTruthy()
    await reset.trigger('click')
    await flushPromises()
    expect(settingsApi.update).toHaveBeenCalledWith({ timezone: null })
    expect(useSettingsStore().data.timezone).toBe('Europe/Istanbul')
  })

  it('shows the API\'s refusal of an unknown zone', async () => {
    settingsApi.update.mockRejectedValue(apiError(422, { message: 'invalid', errors: { timezone: ['The timezone must be a valid zone.'] } }))
    const w = mountCard()
    await w.find('[data-testid="timezone-input"]').setValue('Mars/Olympus')
    expect(w.text()).toContain('This browser does not know this time zone')
    await w.find('[data-testid="timezone-save"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-testid="timezone-error"]').text()).toBe('The timezone must be a valid zone.')
  })

  it('is read-only without settings.manage', () => {
    const w = mountCard({ permissions: ['projects.view'] })
    expect(w.find('[data-testid="timezone-input"]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-testid="timezone-save"]').exists()).toBe(false)
  })
})

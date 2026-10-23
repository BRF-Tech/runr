import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'

vi.mock('@/api/projects', () => ({ projectsApi: { create: vi.fn() } }))
vi.mock('@/composables/useTutorial', () => ({ useCreateTutorial: () => ({ start: vi.fn() }) }))
vi.mock('@/modules/supply-chain/composables/useResidency', () => ({
  useResidency: () => ({ residency: ref(null), load: vi.fn(), isAllowed: () => true }),
}))

import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import CreateProjectModal from '@/components/CreateProjectModal.vue'
import TriggerConfig from '@/components/project/TriggerConfig.vue'
import EndpointTypeSelector from '@/components/project/EndpointTypeSelector.vue'
import GpuConfig from '@/components/project/GpuConfig.vue'
import { withPlugins } from '../helpers'

// The create wizard of a global project (docs/global-projects.md): regions + primary + DNS mode
// on step 1; every trigger (the non-HTTP ones run in the primary), the gateway, GPUs and an
// uploaded image of the primary (copied into every region) as for a regional project.
const stubs = { RuntimeSelector: true }

const REGIONS = [
  { id: 'r-eu', name: 'eu-1', is_active: true, is_synced: true, has_redis: true, global_dns_ready: true },
  { id: 'r-us', name: 'us-1', is_active: true, is_synced: true, global_dns_ready: true },
  { id: 'r-local', name: 'local-1', is_active: true, is_synced: true, global_dns_ready: false },
]

function mountModal() {
  const plugins = withPlugins('en')
  const settings = useSettingsStore()
  settings.data.regions = REGIONS
  settings.data.executor_images = [{ runtime: 'python', version: '3.12', is_default: true }]
  return mount(CreateProjectModal, { global: { ...plugins.global, stubs } })
}

const button = (w, text) => w.findAll('button').find(b => b.text() === text)
const nextBtn = w => button(w, 'Continue →')
const isDisabled = b => b.attributes('disabled') !== undefined

async function globalStepOne(w) {
  await w.find('[data-placement="global"]').trigger('click')
  await w.find('input.input').setValue('Shop')
  await w.find('[data-global-region="eu-1"]').trigger('click')
  await w.find('[data-global-region="us-1"]').trigger('click')
}

async function toLastStep(w) {
  await globalStepOne(w)
  await nextBtn(w).trigger('click')
  await w.findAll('button').find(b => b.text().includes('Container as a Service')).trigger('click')
  await nextBtn(w).trigger('click')
  await w.find('[data-testid="image-ref-input"]').setValue('ghcr.io/acme/shop:1.0')
  await nextBtn(w).trigger('click')
}

beforeEach(() => {
  vi.mocked(projectsApi.create).mockReset()
})

describe('CreateProjectModal: global project', () => {
  it('is a regional project by default and switches to the region picker of a global one', async () => {
    const w = mountModal()
    expect(w.find('[data-placement="regional"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-tour="create-region"]').exists()).toBe(true)

    await w.find('[data-placement="global"]').trigger('click')
    expect(w.find('[data-tour="create-region"]').exists()).toBe(false)
    expect(w.find('[data-testid="global-regions"]').exists()).toBe(true)
    // the region without global DNS is not offered
    expect(w.find('[data-global-region="local-1"]').attributes('disabled')).toBeDefined()
  })

  it('needs two regions on step 1', async () => {
    const w = mountModal()
    await w.find('[data-placement="global"]').trigger('click')
    await w.find('input.input').setValue('Shop')
    await w.find('[data-global-region="eu-1"]').trigger('click')
    expect(isDisabled(nextBtn(w))).toBe(true)
    await w.find('[data-global-region="us-1"]').trigger('click')
    expect(isDisabled(nextBtn(w))).toBe(false)
    expect(w.vm.form.knative_region_id).toBe('r-eu')
  })

  it('offers every trigger, the gateway and the uploaded images of the primary', async () => {
    const w = mountModal()
    await globalStepOne(w)
    await nextBtn(w).trigger('click')
    await w.findAll('button').find(b => b.text().includes('Container as a Service')).trigger('click')
    await nextBtn(w).trigger('click')

    expect(w.text()).toContain('Step 3 / 4')
    // the header names every region, the primary first, and the DNS mode
    expect(w.text()).toContain('eu-1 + us-1')
    expect(w.text()).toContain('High availability')
    expect(w.findComponent(TriggerConfig).exists()).toBe(true)
    expect(w.find('[data-testid="global-trigger"]').text()).toContain('run in the primary region only')
    expect(w.findComponent(EndpointTypeSelector).exists()).toBe(true)
    expect(w.find('[data-testid="uploaded-image-picker"]').exists()).toBe(true)
    expect(w.find('[data-testid="global-image-hint"]').text()).toContain('copied into every region')
    expect(w.vm.form.config.trigger_type).toBe('http')
  })

  it('keeps a schedule trigger and a GPU of a global project', async () => {
    const w = mountModal()
    await globalStepOne(w)
    w.vm.form.config.trigger_type = 'schedule'
    w.vm.form.config.gpu = { ...w.vm.form.config.gpu, enabled: true }
    await w.vm.$nextTick()
    expect(w.vm.form.config.trigger_type).toBe('schedule')
    expect(w.vm.form.config.gpu.enabled).toBe(true)
  })

  it('submits placement, mode and the regions with the primary first', async () => {
    projectsApi.create.mockResolvedValue({ data: { data: { id: 'p-9', name: 'Shop', placement: 'global' } } })
    const w = mountModal()
    await w.find('[data-placement="global"]').trigger('click')
    await w.find('input.input').setValue('Shop')
    await w.find('[data-global-region="eu-1"]').trigger('click')
    await w.find('[data-global-region="us-1"]').trigger('click')
    await w.find('[data-testid="global-primary"]').setValue('r-us')
    await w.find('[data-global-mode="proximity"]').trigger('click')
    await nextBtn(w).trigger('click')
    await w.findAll('button').find(b => b.text().includes('Container as a Service')).trigger('click')
    await nextBtn(w).trigger('click')
    await w.find('[data-testid="image-ref-input"]').setValue('ghcr.io/acme/shop:1.0')
    await nextBtn(w).trigger('click')

    expect(w.text()).toContain('Step 4 / 4')
    // GPUs are admitted in every region of a global project
    expect(w.findComponent(GpuConfig).exists()).toBe(true)
    await w.findAll('button').at(-1).trigger('click')
    await flushPromises()

    const payload = projectsApi.create.mock.calls[0][0]
    expect(payload).toMatchObject({
      name: 'Shop',
      service_type: 'caas',
      placement: 'global',
      global_mode: 'proximity',
      region_ids: ['r-us', 'r-eu'],
      knative_region_id: 'r-us',
      config: { image: 'ghcr.io/acme/shop:1.0', trigger_type: 'http' },
    })
    expect(payload).not.toHaveProperty('endpoint_type')
    expect(payload.config).not.toHaveProperty('gpu')
    expect(w.emitted('created')).toEqual([[{ id: 'p-9', name: 'Shop', placement: 'global' }]])
  })

  it('returns to step 1 with the API error of a region', async () => {
    projectsApi.create.mockRejectedValue({
      response: { status: 422, data: { message: 'invalid', errors: { 'region_ids.1': ['us-1 has no ready global DNS server.'] } } },
    })
    const w = mountModal()
    await toLastStep(w)
    await w.findAll('button').at(-1).trigger('click')
    await flushPromises()

    expect(w.text()).toContain('Step 1 / 4')
    expect(w.find('[data-testid="create-region-error"]').text()).toBe('us-1 has no ready global DNS server.')
    // the name field is not marked
    expect(w.find('input.input').classes()).not.toContain('border-red-500/60')

    // another region choice clears it
    await w.find('[data-global-region="us-1"]').trigger('click')
    expect(w.find('[data-testid="create-region-error"]').exists()).toBe(false)
  })
})

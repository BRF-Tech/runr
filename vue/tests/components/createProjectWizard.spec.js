import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

vi.mock('@/api/projects', () => ({ projectsApi: { create: vi.fn() } }))
vi.mock('@/composables/useTutorial', () => ({ useCreateTutorial: () => ({ start: vi.fn() }) }))

import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import CreateProjectModal from '@/components/CreateProjectModal.vue'
import CreateStep3 from '@/components/project/CreateStep3.vue'
import CreateStep4 from '@/components/project/CreateStep4.vue'
import { withPlugins } from '../helpers'

// Language/runtime choice belongs to the runtime catalog; the wizard only passes it through.
const stubs = { RuntimeSelector: true }

const REGIONS = [
  { id: 'r-ready', name: 'eu-1', is_active: true, is_synced: true, has_redis: true, knative_settings: { request_timeout: 120 } },
  { id: 'r-cron', name: 'region-b', is_active: true, is_synced: false, has_serving: false, has_kubeconfig: true },
  { id: 'r-off', name: 'old', is_active: false, is_synced: true },
]

function mountModal(props = {}) {
  const plugins = withPlugins('en')
  const settings = useSettingsStore()
  settings.data.regions = REGIONS
  settings.data.executor_images = [{ runtime: 'python', version: '3.12', is_default: true }]
  return mount(CreateProjectModal, { props, global: { ...plugins.global, stubs } })
}

const button = (w, text) => w.findAll('button').find(b => b.text() === text)
const nextBtn = w => button(w, 'Continue →')
const isDisabled = b => b.attributes('disabled') !== undefined

async function fillStep1(w, name = 'Orders API', region = 'eu-1') {
  await w.find('input.input').setValue(name)
  await w.findAll('button').find(b => b.text().startsWith(region)).trigger('click')
}

beforeEach(() => {
  vi.mocked(projectsApi.create).mockReset()
})

describe('CreateProjectModal wizard', () => {
  it('step 1 needs a 2+ character, unique, Kubernetes-safe name and a ready region', async () => {
    const w = mountModal({ existingNames: ['Billing'] })
    expect(isDisabled(nextBtn(w))).toBe(true)

    await fillStep1(w, 'x')
    expect(isDisabled(nextBtn(w))).toBe(true)

    await w.find('input.input').setValue('billing')
    expect(w.text()).toContain('A project with this name already exists.')
    expect(isDisabled(nextBtn(w))).toBe(true)

    await w.find('input.input').setValue('42 things')
    expect(w.text()).toContain('starts with a letter')
    expect(isDisabled(nextBtn(w))).toBe(true)

    await w.find('input.input').setValue('Orders API')
    expect(w.text()).toContain('slug: orders-api')
    expect(isDisabled(nextBtn(w))).toBe(false)
  })

  it('does not offer inactive regions', async () => {
    const w = mountModal()
    const inactive = w.findAll('button').find(b => b.text().startsWith('old'))
    expect(isDisabled(inactive)).toBe(true)
  })

  it('step 2 needs a type and applies that type’s default size', async () => {
    const w = mountModal()
    await fillStep1(w)
    await nextBtn(w).trigger('click')
    expect(w.text()).toContain('Step 2 / 4')
    expect(isDisabled(nextBtn(w))).toBe(true)

    await w.findAll('button').find(b => b.text().includes('Container as a Service')).trigger('click')
    expect(isDisabled(nextBtn(w))).toBe(false)
    expect(w.vm.form.config).toMatchObject({ cpu: 500, memory_mb: 512 })
  })

  it('keeps a hand-picked size when the type changes', async () => {
    const w = mountModal()
    await fillStep1(w)
    await nextBtn(w).trigger('click')
    await w.findAll('button').find(b => b.text().includes('Function as a Service')).trigger('click')
    w.vm.form.config.cpu = 1500
    await w.findAll('button').find(b => b.text().includes('Container as a Service')).trigger('click')
    expect(w.vm.form.config.cpu).toBe(1500)
  })

  it('switches to a schedule trigger on regions without Knative Serving', async () => {
    const w = mountModal()
    await fillStep1(w, 'Nightly Job', 'region-b')
    expect(w.vm.form.config.trigger_type).toBe('schedule')
  })

  it('walks a container project through all steps and submits the API payload', async () => {
    projectsApi.create.mockResolvedValue({ data: { data: { id: 'p-1', name: 'Orders API' } } })
    const w = mountModal()

    await fillStep1(w)
    await nextBtn(w).trigger('click')
    await w.findAll('button').find(b => b.text().includes('Container as a Service')).trigger('click')
    await nextBtn(w).trigger('click')

    // Step 3: a container needs an image
    expect(w.text()).toContain('Step 3 / 4')
    expect(isDisabled(nextBtn(w))).toBe(true)
    await w.find('input.font-mono').setValue('ghcr.io/acme/orders:1.0')
    expect(isDisabled(nextBtn(w))).toBe(false)
    await nextBtn(w).trigger('click')

    // Step 4: resources, region timeout cap, env vars
    expect(w.text()).toContain('Step 4 / 4')
    const submit = () => w.findAll('button').at(-1)
    expect(isDisabled(submit())).toBe(false)

    w.vm.form.config.timeout_seconds = 121
    await flushPromises()
    expect(isDisabled(submit())).toBe(true)
    w.vm.form.config.timeout_seconds = 60
    await w.find('textarea').setValue('1BAD=x')
    expect(isDisabled(submit())).toBe(true)
    await w.find('textarea').setValue('LOG_LEVEL=debug')
    expect(isDisabled(submit())).toBe(false)

    await submit().trigger('click')
    await flushPromises()

    expect(projectsApi.create).toHaveBeenCalledTimes(1)
    const payload = projectsApi.create.mock.calls[0][0]
    expect(payload).toMatchObject({
      name: 'Orders API',
      service_type: 'caas',
      knative_region_id: 'r-ready',
      config: {
        image: 'ghcr.io/acme/orders:1.0',
        ports: [80],
        cpu: '500m',
        memory_mb: 512,
        timeout_seconds: 60,
        trigger_type: 'http',
        env_vars: { LOG_LEVEL: 'debug' },
      },
    })
    expect(payload).not.toHaveProperty('runtime')
    expect(w.emitted('created')).toEqual([[{ id: 'p-1', name: 'Orders API' }]])
  })

  it('returns to step 1 when the API rejects the name', async () => {
    projectsApi.create.mockRejectedValue({ response: { status: 422, data: { errors: { name: ['The name has already been taken.'] } } } })
    const w = mountModal()
    await fillStep1(w)
    await nextBtn(w).trigger('click')
    await w.findAll('button').find(b => b.text().includes('Function as a Service')).trigger('click')
    await nextBtn(w).trigger('click')
    await nextBtn(w).trigger('click')
    await w.findAll('button').at(-1).trigger('click')
    await flushPromises()

    expect(w.text()).toContain('Step 1 / 4')
    expect(w.text()).toContain('The name has already been taken.')
    expect(w.emitted('created')).toBeUndefined()
  })

  it('shows other API errors on the last step', async () => {
    projectsApi.create.mockRejectedValue({ response: { status: 422, data: { message: 'Quota exceeded' } } })
    const w = mountModal()
    await fillStep1(w)
    await nextBtn(w).trigger('click')
    await w.findAll('button').find(b => b.text().includes('Function as a Service')).trigger('click')
    await nextBtn(w).trigger('click')
    await nextBtn(w).trigger('click')
    await w.findAll('button').at(-1).trigger('click')
    await flushPromises()

    expect(w.text()).toContain('Step 4 / 4')
    expect(w.text()).toContain('Quota exceeded')
  })
})

describe('CreateStep3', () => {
  function form(overrides = {}) {
    return reactive({
      name: 'Orders API', service_type: 'faas', runtime: 'python', runtime_version: '3.12',
      config: { image: '', port: 80, command: '', trigger_type: 'http', schedule: '', queue_stream: '' },
      endpoint_type: 'subdomain', gateway_path: '', gateway_methods: [],
      ...overrides,
    })
  }

  function mountStep(props) {
    return mount(CreateStep3, {
      props: { runtimes: ['python'], runtimeVersions: [], canNext: true, ...props },
      global: { ...withPlugins('en').global, stubs },
    })
  }

  it('asks for a runtime for functions and an image for containers', () => {
    const fn = mountStep({ form: form() })
    expect(fn.findComponent({ name: 'RuntimeSelector' }).exists()).toBe(true)
    expect(fn.find('[data-tour="create-container"]').exists()).toBe(false)

    const caas = mountStep({ form: form({ service_type: 'caas' }) })
    expect(caas.findComponent({ name: 'RuntimeSelector' }).exists()).toBe(false)
    expect(caas.find('[data-tour="create-container"]').exists()).toBe(true)
  })

  it('writes container fields into the shared form', async () => {
    const f = form({ service_type: 'caas' })
    const w = mountStep({ form: f })
    const [image, port, command] = w.find('[data-tour="create-container"]').findAll('input')
    await image.setValue('nginx:1.27')
    await port.setValue('8080')
    await command.setValue('nginx -g "daemon off;"')
    expect(f.config).toMatchObject({ image: 'nginx:1.27', port: 8080, command: 'nginx -g "daemon off;"' })
  })

  it('hides the port for scheduled containers and the endpoint choice for non-HTTP triggers', () => {
    const w = mountStep({ form: form({ service_type: 'caas', config: { image: 'x', port: 80, command: '', trigger_type: 'schedule', schedule: '', queue_stream: '' } }) })
    expect(w.find('[data-tour="create-container"]').findAll('input')).toHaveLength(2)
    expect(w.findComponent({ name: 'EndpointTypeSelector' }).exists()).toBe(false)
  })

  it('emits back/next and honours canNext', async () => {
    const w = mountStep({ form: form(), canNext: false })
    expect(button(w, 'Continue →').attributes('disabled')).toBeDefined()
    await button(w, '← Back').trigger('click')
    expect(w.emitted('back')).toHaveLength(1)

    await w.setProps({ canNext: true })
    await button(w, 'Continue →').trigger('click')
    expect(w.emitted('next')).toHaveLength(1)
  })
})

describe('CreateStep4', () => {
  function mountStep(props) {
    const form = reactive({
      service_type: 'faas',
      config: { cpu: 250, memory_mb: 256, timeout_seconds: 30, image_pull_policy: 'IfNotPresent', trigger_type: 'http', scale_to_zero: true, min_scale: 0, max_scale: 1, env_vars: '' },
    })
    const w = mount(CreateStep4, { props: { form, canNext: true, ...props }, global: withPlugins('en').global })
    return { w, form }
  }

  it('hides scaling for scheduled projects', async () => {
    const { w, form } = mountStep()
    expect(w.find('[data-tour="create-scale"]').exists()).toBe(true)
    form.config.trigger_type = 'schedule'
    await flushPromises()
    expect(w.find('[data-tour="create-scale"]').exists()).toBe(false)
  })

  it('binds the editors to the shared form', async () => {
    const { w, form } = mountStep()
    await w.find('textarea').setValue('A=1')
    await w.find('select').setValue('medium')
    expect(form.config).toMatchObject({ env_vars: 'A=1', cpu: 1000, memory_mb: 1024 })
  })

  it('disables submit while loading or invalid and shows the error', async () => {
    const { w } = mountStep({ loading: true, error: 'Quota exceeded' })
    const submit = w.findAll('button').at(-1)
    expect(submit.attributes('disabled')).toBeDefined()
    expect(w.text()).toContain('Quota exceeded')

    await w.setProps({ loading: false, canNext: false })
    expect(w.findAll('button').at(-1).attributes('disabled')).toBeDefined()

    await w.setProps({ canNext: true })
    await w.findAll('button').at(-1).trigger('click')
    expect(w.emitted('submit')).toHaveLength(1)
  })
})

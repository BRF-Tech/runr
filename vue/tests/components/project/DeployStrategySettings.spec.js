import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import DeployStrategySettings from '@/components/project/DeployStrategySettings.vue'
import DeployStrategyModal from '@/components/project/DeployStrategyModal.vue'
import { DEFAULTS } from '@/utils/deployStrategy'
import { withPlugins } from '../../helpers'

// Deploy strategy of a function (direct / blue-green / canary): saved on its own endpoint,
// validated like the API, disabled for queue / schedule functions.

const find = (w, id) => w.find(`[data-test="${id}"]`)
const ok = (data) => Promise.resolve({ data })
const fail = (status, data) => Promise.reject(Object.assign(new Error('x'), { response: { status, data } }))

const project = { id: 'p1', name: 'orders-api', service_type: 'faas', config: { trigger_type: 'http' } }

function settings(over = {}) {
  return { ...structuredClone(DEFAULTS), eligible: true, trigger_type: 'http', tracing: true, defaults: structuredClone(DEFAULTS), ...over }
}

function mountSettings({ props = {}, permissions = null, answer = settings() } = {}) {
  const plugins = withPlugins()
  if (permissions) usePermissionsStore().apply({ permissions, platform_admin: false, edition: 'single', role: null, roles: [], projects: {} })
  const load = vi.spyOn(projectsApi, 'deployStrategy').mockImplementation(() => ok({ data: answer }))
  const w = mount(DeployStrategySettings, { props: { project, ...props }, global: plugins.global })
  return { w, load }
}

describe('DeployStrategySettings', () => {
  afterEach(() => vi.restoreAllMocks())

  it('loads the strategy and saves blue-green with its options', async () => {
    useToast().toasts.value = []
    const { w, load } = mountSettings()
    await flushPromises()
    expect(load).toHaveBeenCalledWith('p1')
    expect(find(w, 'deploy-strategy-direct').attributes('aria-checked')).toBe('true')
    expect(find(w, 'deploy-strategy-save').attributes('disabled')).toBeDefined()   // nothing changed

    await find(w, 'deploy-strategy-blue_green').trigger('click')
    expect(find(w, 'blue-green-options').exists()).toBe(true)
    await w.find('[data-testid="blue-green-auto-promote"]').trigger('click')
    await find(w, 'blue-green-health-path').setValue('/healthz')
    await find(w, 'blue-green-health-checks').setValue('5')
    await find(w, 'blue-green-keep-previous').setValue('30')

    const saved = settings({ strategy: 'blue_green', blue_green: { auto_promote: true, health_path: '/healthz', health_checks: 5, keep_previous_minutes: 30 } })
    const update = vi.spyOn(projectsApi, 'updateDeployStrategy').mockImplementation(() => ok({ data: saved }))
    await find(w, 'deploy-strategy-save').trigger('click')
    await flushPromises()

    expect(update).toHaveBeenCalledWith('p1', {
      strategy: 'blue_green',
      blue_green: { auto_promote: true, health_path: '/healthz', health_checks: 5, keep_previous_minutes: 30 },
    })
    expect(w.emitted('saved')[0][0].strategy).toBe('blue_green')
    expect(useToast().toasts.value.at(-1).message).toBe('Deploy strategy saved: Blue-green. It applies from the next deploy.')
    // saved: nothing left to save
    expect(find(w, 'deploy-strategy-save').attributes('disabled')).toBeDefined()
    expect(find(w, 'deploy-strategy-reset').exists()).toBe(false)
  })

  it('edits the canary steps and checks them before sending', async () => {
    const { w } = mountSettings({ answer: settings({ strategy: 'canary' }) })
    await flushPromises()
    expect(find(w, 'canary-options').exists()).toBe(true)
    expect(w.findAll('[data-test^="canary-step-"]').map(i => i.element.value)).toEqual(['10', '50', '100'])

    await find(w, 'canary-step-1').setValue('5')
    expect(find(w, 'error-canary.steps').text()).toBe('Each step must be larger than the one before.')
    expect(find(w, 'deploy-strategy-save').attributes('disabled')).toBeDefined()

    await find(w, 'canary-step-1').setValue('60')
    expect(find(w, 'error-canary.steps').exists()).toBe(false)
    await find(w, 'canary-add-step').trigger('click')          // after 100: a step in between
    expect(w.vm.$.setupState.form.canary.steps).toEqual([10, 60, 80, 100])
    await find(w, 'canary-remove-step-0').trigger('click')
    expect(w.vm.$.setupState.form.canary.steps).toEqual([60, 80, 100])
    await find(w, 'canary-preset-5-25-50-100').trigger('click')
    await find(w, 'canary-p95').setValue('800')
    await find(w, 'canary-error-rate').setValue('')

    const update = vi.spyOn(projectsApi, 'updateDeployStrategy').mockImplementation(() => ok({ data: settings({ strategy: 'canary' }) }))
    await find(w, 'deploy-strategy-save').trigger('click')
    await flushPromises()
    expect(update).toHaveBeenCalledWith('p1', {
      strategy: 'canary',
      canary: { steps: [5, 25, 50, 100], interval_minutes: 5, error_rate_threshold: null, p95_threshold_ms: 800 },
    })
  })

  it('shows the 422 errors of the API next to their fields until the form changes', async () => {
    const { w } = mountSettings({ answer: settings({ strategy: 'canary' }) })
    await flushPromises()
    await find(w, 'canary-step-0').setValue('20')
    vi.spyOn(projectsApi, 'updateDeployStrategy').mockImplementation(() => fail(422, {
      message: 'The canary.steps.1 field must not be greater than 40.',
      errors: { 'canary.steps.1': ['The canary.steps.1 field must not be greater than 40.'], 'canary.interval_minutes': ['The interval is too long.'] },
    }))
    await find(w, 'deploy-strategy-save').trigger('click')
    await flushPromises()

    expect(find(w, 'error-canary.steps.1').text()).toBe('Step 2: The canary.steps.1 field must not be greater than 40.')
    expect(find(w, 'error-canary.interval_minutes').text()).toBe('The interval is too long.')
    expect(find(w, 'deploy-strategy-error').exists()).toBe(false)

    await find(w, 'canary-interval').setValue('10')
    expect(find(w, 'error-canary.steps.1').exists()).toBe(false)
    expect(find(w, 'error-canary.interval_minutes').exists()).toBe(false)
  })

  it('a strategy the API refuses for the trigger, or another failure, is said too', async () => {
    const { w } = mountSettings()
    await flushPromises()
    await find(w, 'deploy-strategy-canary').trigger('click')
    vi.spyOn(projectsApi, 'updateDeployStrategy').mockImplementation(() => fail(422, {
      message: 'Queue and schedule functions deploy directly.',
      errors: { strategy: ['Queue and schedule functions deploy directly.'] },
    }))
    await find(w, 'deploy-strategy-save').trigger('click')
    await flushPromises()
    expect(find(w, 'deploy-strategy-error-strategy').text()).toBe('Queue and schedule functions deploy directly.')

    vi.spyOn(projectsApi, 'updateDeployStrategy').mockImplementation(() => fail(500, { message: 'Server Error' }))
    await w.vm.save()
    await flushPromises()
    expect(find(w, 'deploy-strategy-error').text()).toBe('Server Error')
  })

  it('is disabled with an explanation for a queue function', async () => {
    const { w } = mountSettings({ answer: settings({ eligible: false, trigger_type: 'queue' }) })
    await flushPromises()
    expect(find(w, 'deploy-strategy-ineligible').text()).toContain('This function has the Queue trigger, so it always deploys directly.')
    expect(find(w, 'deploy-strategy-canary').attributes('disabled')).toBeDefined()
    await find(w, 'deploy-strategy-canary').trigger('click')
    expect(find(w, 'deploy-strategy-direct').attributes('aria-checked')).toBe('true')
    expect(find(w, 'deploy-strategy-save').exists()).toBe(false)
  })

  it('canary without tracing progresses on time only; the link opens the Traces tab', async () => {
    const { w } = mountSettings({ answer: settings({ strategy: 'canary', tracing: false }), props: { tracingLink: true } })
    await flushPromises()
    expect(find(w, 'canary-no-tracing').text()).toContain('Knative\'s request metrics of the new revision')
    expect(find(w, 'canary-no-tracing').text()).toContain('otherwise the canary progresses on time only')
    await find(w, 'canary-open-tracing').trigger('click')
    expect(w.emitted('open-tracing')).toHaveLength(1)

    const { w: withTracing } = mountSettings({ answer: settings({ strategy: 'canary', tracing: true }) })
    await flushPromises()
    expect(find(withTracing, 'canary-no-tracing').exists()).toBe(false)
    expect(withTracing.text()).toContain("The new revision's own requests are judged, once the window has at least 10.")

    const { w: noLink } = mountSettings({ answer: settings({ strategy: 'canary', tracing: false }) })
    await flushPromises()
    expect(find(noLink, 'canary-open-tracing').exists()).toBe(false)
  })

  it('without projects.update the strategy is shown read-only', async () => {
    const { w } = mountSettings({ permissions: ['projects.view'], answer: settings({ strategy: 'blue_green' }) })
    await flushPromises()
    expect(find(w, 'deploy-strategy-readonly').text()).toBe('You can view this, changes need the projects.update permission.')
    expect(find(w, 'deploy-strategy-canary').attributes('disabled')).toBeDefined()
    expect(find(w, 'blue-green-options').attributes('disabled')).toBeDefined()
    expect(find(w, 'deploy-strategy-save').exists()).toBe(false)
  })

  it('explains a trigger change of the edit form that is not saved yet', async () => {
    const { w } = mountSettings({ props: { triggerType: 'queue' } })
    await flushPromises()
    expect(find(w, 'deploy-strategy-trigger-changed').exists()).toBe(true)

    const { w: same } = mountSettings({ props: { triggerType: 'workflow' } })
    await flushPromises()
    expect(find(same, 'deploy-strategy-trigger-changed').exists()).toBe(false)
  })

  it('says when the strategy cannot be loaded', async () => {
    const plugins = withPlugins()
    vi.spyOn(projectsApi, 'deployStrategy').mockImplementation(() => fail(404, { message: 'Not found.' }))
    const w = mount(DeployStrategySettings, { props: { project }, global: plugins.global })
    await flushPromises()
    expect(find(w, 'deploy-strategy-load-error').text()).toBe('Not found.')
  })
})

// An environment's settings (docs/deploy-strategies.md#environments): the project's unless it sets
// its own; saving sends only what changed, "Use the project's" drops what it sets.
describe('DeployStrategySettings of an environment', () => {
  afterEach(() => vi.restoreAllMocks())

  const inherited = () => settings({ strategy: 'blue_green', environment: 'staging', own: {}, inherited: true, project: 'blue_green' })

  it('loads the environment\'s settings and saves only what changed', async () => {
    useToast().toasts.value = []
    const { w, load } = mountSettings({ props: { environment: 'staging' }, answer: inherited() })
    await flushPromises()
    expect(load).toHaveBeenCalledWith('p1', 'staging')
    expect(find(w, 'deploy-strategy-inherited').text()).toBe('staging follows the project\'s settings (Blue-green). A change here applies to staging only.')
    expect(find(w, 'deploy-strategy-use-project').exists()).toBe(false)

    await find(w, 'deploy-strategy-canary').trigger('click')
    await find(w, 'canary-step-0').setValue('25')

    const saved = settings({ strategy: 'canary', canary: { ...DEFAULTS.canary, steps: [25, 50, 100] }, environment: 'staging', own: { strategy: 'canary', canary: { steps: [25, 50, 100] } }, inherited: false, project: 'blue_green' })
    const update = vi.spyOn(projectsApi, 'updateDeployStrategy').mockImplementation(() => ok({ data: saved }))
    await find(w, 'deploy-strategy-save').trigger('click')
    await flushPromises()

    expect(update).toHaveBeenCalledWith('p1', { strategy: 'canary', canary: { steps: [25, 50, 100] } }, 'staging')
    expect(w.emitted('saved')[0][0].environment).toBe('staging')
    expect(find(w, 'deploy-strategy-own').text()).toBe('staging has settings of its own; the project uses Blue-green. What it does not set follows the project.')
  })

  it('goes back to the project\'s settings', async () => {
    useToast().toasts.value = []
    const own = settings({ strategy: 'canary', environment: 'staging', own: { strategy: 'canary' }, inherited: false, project: 'direct' })
    const { w } = mountSettings({ props: { environment: 'staging' }, answer: own })
    await flushPromises()

    const update = vi.spyOn(projectsApi, 'updateDeployStrategy').mockImplementation(() => ok({ data: settings({ environment: 'staging', own: {}, inherited: true, project: 'direct' }) }))
    await find(w, 'deploy-strategy-use-project').trigger('click')
    await flushPromises()

    expect(update).toHaveBeenCalledWith('p1', { reset: true }, 'staging')
    expect(find(w, 'deploy-strategy-direct').attributes('aria-checked')).toBe('true')
    expect(find(w, 'deploy-strategy-inherited').exists()).toBe(true)
    expect(useToast().toasts.value.at(-1).message).toBe('staging follows the project\'s settings again: Direct.')
  })

  it('production is the project\'s own settings', async () => {
    const { w, load } = mountSettings({ props: { environment: 'production' } })
    await flushPromises()
    expect(load).toHaveBeenCalledWith('p1')
    expect(find(w, 'deploy-strategy-environment').exists()).toBe(false)
  })

  it('without environments.manage an environment\'s settings are read-only', async () => {
    const { w } = mountSettings({ props: { environment: 'staging' }, permissions: ['projects.view', 'projects.update'], answer: inherited() })
    await flushPromises()
    expect(find(w, 'deploy-strategy-readonly').text()).toBe('You can view this, changes need the environments.manage permission.')
    expect(find(w, 'deploy-strategy-canary').attributes('disabled')).toBeDefined()
  })

  it('the modal names the environment', async () => {
    const plugins = withPlugins()
    vi.spyOn(projectsApi, 'deployStrategy').mockImplementation(() => ok({ data: inherited() }))
    const w = mount(DeployStrategyModal, { props: { project, environment: 'staging' }, global: plugins.global })
    await flushPromises()
    expect(find(w, 'deploy-strategy-modal-env').text()).toBe('· staging')
    expect(w.findComponent(DeployStrategySettings).props('environment')).toBe('staging')
  })
})

describe('DeployStrategyModal', () => {
  afterEach(() => vi.restoreAllMocks())

  it('wraps the settings and passes their events on', async () => {
    const plugins = withPlugins()
    vi.spyOn(projectsApi, 'deployStrategy').mockImplementation(() => ok({ data: settings({ strategy: 'canary', tracing: false }) }))
    const w = mount(DeployStrategyModal, { props: { project, tracingLink: true }, global: plugins.global })
    await flushPromises()
    expect(w.text()).toContain('Deploy strategy — orders-api')
    await find(w, 'canary-open-tracing').trigger('click')
    expect(w.emitted('open-tracing')).toHaveLength(1)
    w.findComponent(DeployStrategySettings).vm.$emit('saved', { strategy: 'canary' })
    expect(w.emitted('saved')[0][0]).toEqual({ strategy: 'canary' })
    await w.find('button[title="Close"]').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
  })
})

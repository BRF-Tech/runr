import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

// Every region API call resolves to { data: {} } unless a test says otherwise.
vi.mock('@/api/knativeRegions', () => {
  const fns = {}
  return {
    knativeRegionsApi: new Proxy(fns, {
      get: (target, key) => (target[key] ??= vi.fn(async () => ({ data: {} }))),
    }),
  }
})

import { knativeRegionsApi as api } from '@/api/knativeRegions'
import { useRegistrySetup } from '@/composables/useRegistrySetup'
import { useRegionDetail } from '@/composables/useRegionDetail'
import { dispatchPlatformEvent, useJobEvents } from '@/composables/useJobEvents'
import { moduleRegistry } from '@/modules/index'
import { withPlugins } from '../helpers'

// Composables with lifecycle hooks need a component instance.
function harness(useFn, props = {}) {
  let exposed
  const Comp = defineComponent({
    props: { region: { type: Object, default: null } },
    emits: ['saved', 'close'],
    setup(p, { emit }) {
      exposed = useFn(p, emit)
      return () => h('div')
    },
  })
  const wrapper = mount(Comp, { props, global: withPlugins('en').global })
  return { wrapper, api: exposed }
}

beforeEach(() => {
  for (const key of Object.keys(api)) api[key].mockReset().mockResolvedValue({ data: {} })
})

describe('useJobEvents', () => {
  it('delivers exact and wildcard topics until the component unmounts', () => {
    const exact = vi.fn()
    const wild = vi.fn()
    const { wrapper } = harness(() => useJobEvents({ 'knative.operator.installed': exact, 'knative.*': wild }))

    dispatchPlatformEvent('knative.operator.installed', { region_id: 'r1' })
    dispatchPlatformEvent('knative.serving.install_failed', { region_id: 'r1' })
    dispatchPlatformEvent('sandbox.kata.installed', {})

    expect(exact).toHaveBeenCalledTimes(1)
    expect(exact).toHaveBeenCalledWith({ region_id: 'r1' }, 'knative.operator.installed')
    expect(wild.mock.calls.map(c => c[1])).toEqual(['knative.operator.installed', 'knative.serving.install_failed'])

    wrapper.unmount()
    dispatchPlatformEvent('knative.operator.installed', {})
    expect(exact).toHaveBeenCalledTimes(1)
    expect(wild).toHaveBeenCalledTimes(2)
  })
})

describe('useRegistrySetup validation', () => {
  it('internal PVC registry needs a storage class and size', () => {
    const { api: r } = harness(() => useRegistrySetup())
    expect(r.hasValidationError.value).toBe(true)
    r.pvcConfig.value.storageClass = 'longhorn'
    expect(r.hasValidationError.value).toBe(false)
    r.pvcConfig.value.size = ''
    expect(r.hasValidationError.value).toBe(true)
  })

  it('internal S3 registry needs every S3 field', () => {
    const { api: r } = harness(() => useRegistrySetup())
    r.storageType.value = 's3'
    const full = { endpoint: 'https://s3', bucket: 'b', region: 'eu', access_key: 'ak', secret_key: 'sk' }
    for (const missing of Object.keys(full)) {
      r.s3Config.value = { ...full, [missing]: '' }
      expect(r.hasValidationError.value, missing).toBe(true)
    }
    r.s3Config.value = full
    expect(r.hasValidationError.value).toBe(false)
  })

  it('external registry needs only an address', () => {
    const { api: r } = harness(() => useRegistrySetup())
    r.registryMode.value = 'external'
    expect(r.hasValidationError.value).toBe(true)
    r.externalConfig.value.address = 'registry.example.com'
    expect(r.hasValidationError.value).toBe(false)
  })

  it('builds the registry payload for each mode', () => {
    const { api: r } = harness(() => useRegistrySetup())
    r.pvcConfig.value = { storageClass: 'longhorn', size: '20Gi' }
    expect(r.getRegistryPayload()).toEqual({ registry_mode: 'internal', storage_type: 'pvc', pvc_config: { storageClass: 'longhorn', size: '20Gi' } })

    r.registryMode.value = 'external'
    r.externalConfig.value = { address: 'reg.example.com', username: '', password: '' }
    expect(r.getRegistryPayload()).toEqual({
      registry_mode: 'external', external_address: 'reg.example.com', external_username: null, external_password: null,
    })
  })

  it('sends monitoring reuse URLs trimmed, and the Loki org only with a Loki URL', () => {
    const { api: r } = harness(() => useRegistrySetup())
    expect(r.getMonitoringPayload()).toEqual({})
    r.monitoringConfig.value = { prometheusUrl: '  http://prom:9090 ', lokiUrl: '', lokiOrgId: 'tenant-1' }
    expect(r.getMonitoringPayload()).toEqual({ prometheus_url: 'http://prom:9090' })
    r.monitoringConfig.value.lokiUrl = 'http://loki:3100'
    expect(r.getMonitoringPayload()).toEqual({ prometheus_url: 'http://prom:9090', loki_url: 'http://loki:3100', loki_org_id: 'tenant-1' })
  })

  it('starts setup and follows progress events for its own setup id only', async () => {
    api.registryStart.mockResolvedValue({ data: { setup_token: 'tok-1', setup_id: 'sid-1' } })
    const { api: r } = harness(() => useRegistrySetup())
    r.pvcConfig.value.storageClass = 'longhorn'
    await r.startSetup({ name: 'eu-1', kubeconfig: 'kc', nodes: ['n1'] })

    expect(api.registryStart).toHaveBeenCalledWith(expect.objectContaining({ name: 'eu-1', registry_mode: 'internal', storage_type: 'pvc' }))
    expect(r.setupStatus.value).toBe('pending')
    expect(r.isSettingUp.value).toBe(true)

    dispatchPlatformEvent('registry.progress', { setup_id: 'other', status: 'error', phase: 'x' })
    expect(r.setupStatus.value).toBe('pending')

    dispatchPlatformEvent('region.created', { setup_id: 'other', region_id: 'r-x' })
    expect(r.setupRegionId.value).toBe(null)

    dispatchPlatformEvent('registry.progress', { setup_id: 'sid-1', status: 'installing', phase: 'zot_install', message: 'Installing Zot' })
    expect(r.setupPhase.value).toBe('zot_install')
    expect(r.setupMessage.value).toBe('Installing Zot')

    dispatchPlatformEvent('region.created', { setup_id: 'sid-1', region_id: 'r-9' })
    expect(r.canProceed.value).toBe(true)
    expect(r.setupRegionId.value).toBe('r-9')
  })

  // A job that fails fast reports before the start request answers with the setup id: the event
  // is kept until the id is known, or the wizard would wait forever
  it('applies a failure broadcast before the start request answered', async () => {
    let answer
    api.registryStart.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))
    const { api: r } = harness(() => useRegistrySetup())
    const starting = r.startSetup({ name: 'eu-1', kubeconfig: 'kc', nodes: [] })

    dispatchPlatformEvent('region.create_failed', { setup_id: 'sid-2', error: 'registry answered 401' })
    dispatchPlatformEvent('region.create_failed', { setup_id: 'someone-else', error: 'not ours' })
    answer({ data: { setup_token: 'tok-2', setup_id: 'sid-2' } })
    await starting

    expect(r.setupStatus.value).toBe('error')
    expect(r.setupError.value).toBe('registry answered 401')
    expect(r.setupLoading.value).toBe(false)
  })

  it('reports the API validation message when setup cannot start', async () => {
    api.registryStart.mockRejectedValue({ response: { data: { errors: { kubeconfig: ['Cluster unreachable'] } } } })
    const { api: r } = harness(() => useRegistrySetup())
    await r.startSetup({})
    expect(r.setupError.value).toBe('Cluster unreachable')
    expect(r.setupLoading.value).toBe(false)
  })

  it('stops listening after unmount', async () => {
    api.registryStart.mockResolvedValue({ data: { setup_token: 'tok-2', setup_id: 'sid-2' } })
    const { api: r, wrapper } = harness(() => useRegistrySetup())
    await r.startSetup({})
    wrapper.unmount()
    dispatchPlatformEvent('registry.progress', { setup_id: 'sid-2', status: 'installing', phase: 'p' })
    expect(r.setupStatus.value).toBe('pending')
  })
})

describe('region wizard step 1 (useRegionDetail)', () => {
  it('needs a name of at least two characters', () => {
    const { api: d } = harness(useRegionDetail)
    expect(d.canSaveGeneral.value).toBe(false)
    d.form.value.name = ' a '
    expect(d.canSaveGeneral.value).toBe(false)
    d.form.value.name = 'eu'
    expect(d.canSaveGeneral.value).toBe(true)
  })

  it('requires a kubeconfig before contacting the cluster', async () => {
    const { api: d } = harness(useRegionDetail)
    d.form.value.name = 'eu-1'
    await d.saveGeneral()
    expect(d.error.value).toBe('Kubeconfig file is required.')
    expect(api.validateKubeconfig).not.toHaveBeenCalled()
  })

  it('stops when the region namespace already exists on the cluster', async () => {
    api.checkNamespacePreview.mockResolvedValue({ data: { exists: true } })
    const { api: d } = harness(useRegionDetail)
    d.form.value.name = ' eu-1 '
    d.kubeconfigMode.value = 'paste'
    d.kubeconfigPaste.value = '  apiVersion: v1  '

    await d.saveGeneral()

    expect(api.validateKubeconfig).toHaveBeenCalledWith({ kubeconfig: 'apiVersion: v1' })
    expect(api.checkNamespacePreview).toHaveBeenCalledWith({ kubeconfig: 'apiVersion: v1', name: 'eu-1' })
    expect(d.error.value).toContain('eu-1')
    expect(d.wizardStep.value).toBe(1)
    expect(d.saving.value).toBe(false)
  })

  it('moves to the workers step, using the normalised kubeconfig and loading nodes', async () => {
    api.validateKubeconfig.mockResolvedValue({ data: { kubeconfig: 'normalised' } })
    api.checkNamespacePreview.mockResolvedValue({ data: { exists: false } })
    api.previewNodes.mockResolvedValue({ data: { nodes: [{ name: 'n1', is_selected: true }, { name: 'n2', is_selected: false }] } })
    api.previewStorageClasses.mockResolvedValue({ data: { storageClasses: [{ name: 'local', is_default: false }, { name: 'longhorn', is_default: true }] } })
    const { api: d } = harness(useRegionDetail)
    d.form.value.name = 'eu-1'
    d.kubeconfigMode.value = 'paste'
    d.kubeconfigPaste.value = 'raw'

    await d.saveGeneral()
    await flushPromises()

    expect(d.kubeconfigPaste.value).toBe('normalised')
    expect(d.wizardStep.value).toBe(2)
    expect(api.previewNodes).toHaveBeenCalledWith({ kubeconfig: 'normalised' })
    expect(d.nodes.value).toHaveLength(2)
    expect(d.selectedNodes.value).toEqual(['n1'])
    expect(d.registry.pvcConfig.value.storageClass).toBe('longhorn')
  })

  it.each([
    [{ response: { data: { message: 'Invalid kubeconfig' } } }, 'Invalid kubeconfig'],
    [{ response: { data: { errors: { kubeconfig: ['Unreachable API server'] } } } }, 'Unreachable API server'],
    [new Error('network'), 'An error occurred.'],
  ])('shows the API error (%#)', async (err, message) => {
    api.validateKubeconfig.mockRejectedValue(err)
    const { api: d } = harness(useRegionDetail)
    d.form.value.name = 'eu-1'
    d.form.value.kubeconfig = 'from-file'
    await d.saveGeneral()
    expect(d.error.value).toBe(message)
    expect(d.wizardStep.value).toBe(1)
  })

  it('toggles worker nodes', () => {
    const { api: d } = harness(useRegionDetail)
    d.toggleNode('n1')
    d.toggleNode('n2')
    d.toggleNode('n1')
    expect(d.selectedNodes.value).toEqual(['n2'])
  })

  it('marks earlier steps read-only once the wizard moved on', async () => {
    const { api: d } = harness(useRegionDetail)
    d.wizardStep.value = 3
    await flushPromises()
    d.wizardStep.value = 2
    await flushPromises()
    expect(d.highestStepReached.value).toBe(3)
    expect(d.isStepReadonly.value).toBe(true)
  })
})

describe('region hooks of modules (moduleRegistry.regionTabs / regionCreateExtensions)', () => {
  const tab = { key: 'billing', labelKey: 'billing.region.tab', component: { render: () => null }, visibleIf: (region) => !!region?.id, order: 50 }
  const later = { key: 'zz', labelKey: 'zz', component: { render: () => null }, order: 90 }
  const afterCreate = vi.fn(async () => {})
  const ext = { key: 'billing', component: { render: () => null }, initial: () => ({ billing_type: 'payg' }), afterCreate }

  beforeEach(() => {
    moduleRegistry.regionTabs.push(later, tab)
    moduleRegistry.regionCreateExtensions.push(ext, { key: 'plain', component: { render: () => null } })
    afterCreate.mockReset()
  })
  afterEach(() => {
    moduleRegistry.regionTabs.splice(0)
    moduleRegistry.regionCreateExtensions.splice(0)
  })

  it('adds the visible module tabs of a region in order', () => {
    const { api: edit } = harness(useRegionDetail, { region: { id: 'r1', name: 'eu-1' } })
    expect(edit.moduleRegionTabs.value.map(t => t.key)).toEqual(['billing', 'zz'])
    const { api: create } = harness(useRegionDetail)
    expect(create.moduleRegionTabs.value.map(t => t.key)).toEqual(['zz'])
  })

  it('starts the wizard with the modules\' initial values and hands them over once the region exists', async () => {
    const { api: d } = harness(useRegionDetail)
    expect(d.form.value.extensions).toEqual({ billing: { billing_type: 'payg' }, plain: null })
    d.form.value.extensions.billing = { billing_type: 'fixed' }
    await d.runCreateExtensions({ id: 'r9' })
    expect(afterCreate).toHaveBeenCalledWith({ id: 'r9' }, { billing_type: 'fixed' })

    afterCreate.mockRejectedValueOnce({ response: { data: { message: 'Billing type not saved.' } } })
    await d.runCreateExtensions({ id: 'r9' })
    expect(d.workersError.value).toBe('Billing type not saved.')
  })
})

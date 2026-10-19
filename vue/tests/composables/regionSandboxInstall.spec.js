import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'
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
const confirmMock = vi.fn(async () => true)
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

import { knativeRegionsApi as api } from '@/api/knativeRegions'
import { useSandboxTab } from '@/composables/useSandboxTab'
import { useMigration } from '@/composables/useMigration'
import { useInstallPopup } from '@/composables/useInstallPopup'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'
import { makeI18n, freshPinia } from '@/test-support/helpers'

const i18n = makeI18n()
const mounted = []

/** A composable with lifecycle hooks, inside a component */
function harness(useFn) {
  let exposed
  const emit = vi.fn()
  const Comp = defineComponent({
    setup() {
      exposed = useFn(emit)
      return () => h('div')
    },
  })
  const wrapper = mount(Comp, { global: { plugins: [freshPinia(), i18n] } })
  mounted.push(wrapper)
  return { ...exposed, emit, wrapper }
}

const t = (key, params) => (params ? `${key} ${JSON.stringify(params)}` : key)
const httpError = (status, message) => Object.assign(new Error(`HTTP ${status}`), { response: { status, data: message ? { message } : {} } })

beforeEach(() => {
  for (const key of Object.keys(api)) api[key].mockReset().mockResolvedValue({ data: {} })
  confirmMock.mockReset().mockResolvedValue(true)
})
afterEach(() => {
  mounted.splice(0).forEach(w => w.unmount())
  vi.useRealTimers()
})

describe('Region → Sandbox (useSandboxTab)', () => {
  const sandbox = (confirm = confirmMock) => harness(emit => useSandboxTab(ref('r1'), emit, t, confirm))

  it('reads the runtimes and the workload mapping from the region, moving legacy "kata" to the installed hypervisor', () => {
    const s = sandbox()
    s.initFromRegion(null)
    expect(s.kataStatus.value).toBe('not_installed')

    s.initFromRegion({
      sandbox_kata_status: 'ready', sandbox_kata_status_message: 'ok', sandbox_kata_hypervisor: ['qemu', 'clh'],
      sandbox_gvisor_status: 'error', sandbox_gvisor_status_message: 'no nodes',
      sandbox_xaas_runtime: 'kata', sandbox_faas_runtime: 'gvisor', sandbox_caas_runtime: null, sandbox_build_runtime: 'kata-clh',
    })

    expect(s.kataStatus.value).toBe('ready')
    expect(s.gvisorMessage.value).toBe('no nodes')
    expect(s.mapping.value).toEqual({ xaas: 'kata-qemu', faas: 'gvisor', caas: 'runc', build: 'kata-clh' })
    expect(s.selectedShims.value).toEqual(['qemu', 'clh'])

    // A single hypervisor stored as a string
    s.initFromRegion({ sandbox_kata_status: 'ready', sandbox_kata_hypervisor: 'fc', sandbox_xaas_runtime: 'kata' })
    expect(s.selectedShims.value).toEqual(['fc'])
    expect(s.mapping.value.xaas).toBe('kata-fc')
    expect(s.fcSelected.value).toBe(true)
  })

  it('offers only the runtimes that are ready, and no Kata when no node has /dev/kvm', async () => {
    const s = sandbox()
    expect(s.runtimeOptions.value.map(o => o.value)).toEqual(['runc'])

    s.initFromRegion({ sandbox_kata_status: 'ready', sandbox_kata_hypervisor: ['clh', 'custom'], sandbox_gvisor_status: 'ready' })
    expect(s.runtimeOptions.value).toEqual([
      { value: 'runc', label: 'settings.regionDetail.sandbox.runtimeRunc' },
      { value: 'kata-clh', label: 'Kata CLH' },
      { value: 'kata-custom', label: 'Kata custom' },
      { value: 'gvisor', label: 'settings.regionDetail.sandbox.runtimeGvisor' },
    ])

    // Ready without a recorded hypervisor: the plain Kata runtime class
    s.kataHypervisor.value = null
    expect(s.runtimeOptions.value.map(o => o.value)).toContain('kata')

    api.kataShims.mockResolvedValue({ data: { shims: [{ name: 'clh' }], kvm: { state: 'no' } } })
    await s.loadShims()
    expect(s.kataBlocked.value).toBe(true)
    expect(s.runtimeOptions.value.map(o => o.value)).toEqual(['runc', 'gvisor'])
  })

  it('loads the shims, the /dev/kvm state and the Firecracker pool limits', async () => {
    const s = sandbox()
    api.kataShims.mockResolvedValue({ data: { shims: [{ name: 'fc' }], kvm: { state: 'yes' }, devmapper: { default_pool_gb: 40, min_pool_gb: 10, max_pool_gb: 200 } } })
    await s.loadShims()
    expect(s.shims.value).toEqual([{ name: 'fc' }])
    expect(s.kvm.value).toEqual({ state: 'yes' })
    expect(s.devmapperPoolGb.value).toBe(40)
    expect(s.devmapperLimits.value.max_pool_gb).toBe(200)

    // An unreachable cluster leaves the last answer and ends loading
    api.kataShims.mockRejectedValue(httpError(502))
    await s.loadShims()
    expect(s.shimsLoading.value).toBe(false)
    expect(s.shims.value).toEqual([{ name: 'fc' }])
  })

  it('asks before installing Kata and sends the hypervisors with the Firecracker pool size', async () => {
    const s = sandbox()
    s.selectedShims.value = ['clh', 'fc']
    s.devmapperPoolGb.value = '64'
    api.installSandbox.mockResolvedValue({ data: { region: { data: { id: 'r1', sandbox_kata_status: 'installing' } } } })

    await s.installRuntime('kata')

    expect(confirmMock).toHaveBeenCalledTimes(1)
    expect(confirmMock.mock.calls[0][0].message).toContain('settings.regionDetail.sandbox.kata.fcHint')
    expect(api.installSandbox).toHaveBeenCalledWith('r1', { runtime: 'kata', hypervisors: ['clh', 'fc'], devmapper_pool_gb: 64 })
    expect(s.kataStatus.value).toBe('installing')
    expect(s.emit).toHaveBeenCalledWith('saved', { id: 'r1', sandbox_kata_status: 'installing' })
    expect(s.installing.value.kata).toBe(false)
  })

  it('does nothing when the Kata install is not confirmed', async () => {
    confirmMock.mockResolvedValue(false)
    const s = sandbox()
    await s.installRuntime('kata')
    expect(confirmMock.mock.calls[0][0].message).not.toContain('fcHint')
    expect(api.installSandbox).not.toHaveBeenCalled()
  })

  it('installs gVisor without asking and shows why an install failed', async () => {
    const s = sandbox()
    api.installSandbox.mockRejectedValueOnce(httpError(422, 'no eligible nodes'))
    await s.installRuntime('gvisor')
    expect(confirmMock).not.toHaveBeenCalled()
    expect(api.installSandbox).toHaveBeenCalledWith('r1', { runtime: 'gvisor' })
    expect(s.gvisorMessage.value).toBe('no eligible nodes')

    api.installSandbox.mockRejectedValueOnce(new Error('Network Error'))
    await s.installRuntime('kata')
    expect(s.kataMessage.value).toBe('Network Error')
    expect(s.installing.value).toEqual({ gvisor: false, kata: false })

    // An answer without a region changes nothing
    api.installSandbox.mockResolvedValueOnce({ data: {} })
    await s.installRuntime('gvisor')
    expect(s.emit).not.toHaveBeenCalled()
  })

  it('uninstalls a runtime after a destructive confirmation', async () => {
    const s = sandbox()
    confirmMock.mockResolvedValueOnce(false)
    await s.confirmUninstall('gvisor')
    expect(api.uninstallSandbox).not.toHaveBeenCalled()

    api.uninstallSandbox.mockResolvedValueOnce({ data: { region: { id: 'r1', sandbox_gvisor_status: 'uninstalling' } } })
    await s.confirmUninstall('gvisor')
    expect(confirmMock.mock.calls[1][0]).toMatchObject({ title: 'settings.regionDetail.sandbox.gvisor.uninstallBtn', destructive: true })
    expect(api.uninstallSandbox).toHaveBeenCalledWith('r1', { runtime: 'gvisor' })
    expect(s.gvisorStatus.value).toBe('uninstalling')
    expect(s.emit).toHaveBeenCalledWith('saved', { id: 'r1', sandbox_gvisor_status: 'uninstalling' })

    api.uninstallSandbox.mockRejectedValueOnce(httpError(409, 'workloads still use Kata'))
    await s.confirmUninstall('kata')
    expect(confirmMock.mock.calls[2][0].title).toBe('settings.regionDetail.sandbox.kata.uninstallBtn')
    expect(s.kataMessage.value).toBe('workloads still use Kata')

    api.uninstallSandbox.mockResolvedValueOnce({ data: {} })
    await s.confirmUninstall('kata')
    expect(s.installing.value.kata).toBe(false)
  })

  it('saves the workload mapping and clears the saved notice after a while', async () => {
    vi.useFakeTimers()
    const s = sandbox()
    s.mapping.value = { xaas: 'gvisor', faas: 'runc', caas: 'kata-clh', build: 'runc' }
    api.updateSandboxMapping.mockResolvedValueOnce({ data: { region: { id: 'r1', sandbox_xaas_runtime: 'gvisor', sandbox_caas_runtime: 'kata-clh' } } })

    await s.saveMapping()

    expect(api.updateSandboxMapping).toHaveBeenCalledWith('r1', {
      sandbox_xaas_runtime: 'gvisor', sandbox_faas_runtime: 'runc', sandbox_caas_runtime: 'kata-clh', sandbox_build_runtime: 'runc',
    })
    expect(s.mapping.value).toEqual({ xaas: 'gvisor', faas: 'runc', caas: 'kata-clh', build: 'runc' })
    expect(s.mappingSuccess.value).toBe(true)
    vi.advanceTimersByTime(3000)
    expect(s.mappingSuccess.value).toBe(false)

    api.updateSandboxMapping.mockResolvedValueOnce({ data: {} })
    await s.saveMapping()
    expect(s.emit).toHaveBeenCalledTimes(1)

    api.updateSandboxMapping.mockRejectedValueOnce(httpError(422, 'gvisor is not installed'))
    await s.saveMapping()
    expect(s.mappingError.value).toBe('gvisor is not installed')
    expect(s.mappingSaving.value).toBe(false)

    api.updateSandboxMapping.mockRejectedValueOnce(new Error('timeout'))
    await s.saveMapping()
    expect(s.mappingError.value).toBe('timeout')
  })

  it('labels every status and passes unknown ones through', () => {
    const s = sandbox()
    expect(['not_installed', 'installing', 'uninstalling', 'ready', 'error', 'odd'].map(s.sandboxStatusLabel)).toEqual([
      'settings.regionDetail.sandbox.statusNotInstalled',
      'settings.regionDetail.sandbox.statusInstalling',
      'settings.regionDetail.sandbox.statusUninstalling',
      'settings.regionDetail.sandbox.statusReady',
      'settings.regionDetail.sandbox.statusError',
      'odd',
    ])
  })

  it('follows the sandbox events of its own region', async () => {
    const s = sandbox()
    api.sandboxStatus.mockResolvedValue({ data: { kata: { status: 'ready', message: '', hypervisor: 'clh' }, gvisor: { status: 'ready' }, mapping: { xaas: 'kata-clh', faas: 'runc', caas: 'runc', build: 'runc' } } })

    dispatchPlatformEvent('sandbox.kata.installed', { region_id: 'other' })
    await flushPromises()
    expect(api.sandboxStatus).not.toHaveBeenCalled()

    s.installing.value = { kata: true }
    dispatchPlatformEvent('sandbox.kata.installed', { region_id: 'r1' })
    await flushPromises()
    expect(api.sandboxStatus).toHaveBeenCalledWith('r1')
    expect(s.kataStatus.value).toBe('ready')
    expect(s.kataHypervisor.value).toEqual(['clh'])
    expect(s.mapping.value.xaas).toBe('kata-clh')
    expect(s.installing.value.kata).toBe(false)

    // A failure shows the error until the status (with its step log) arrives
    api.sandboxStatus.mockReturnValueOnce(new Promise(() => {}))
    dispatchPlatformEvent('sandbox.gvisor.install_failed', { region_id: 'r1', error: 'runsc missing' })
    expect(s.gvisorStatus.value).toBe('error')
    expect(s.gvisorMessage.value).toBe('runsc missing')

    // A failure while a message is shown keeps it
    s.kataMessage.value = 'step 3/5'
    api.sandboxStatus.mockRejectedValueOnce(new Error('gone'))
    dispatchPlatformEvent('sandbox.kata.install_failed', { region_id: 'r1' })
    await flushPromises()
    expect(s.kataStatus.value).toBe('error')
    expect(s.kataMessage.value).toBe('step 3/5')

    // The background /dev/kvm check finished: the hypervisor list is loaded again
    api.kataShims.mockResolvedValue({ data: { shims: [], kvm: { state: 'mixed' } } })
    dispatchPlatformEvent('sandbox.kvm.probed', { region_id: 'r1' })
    await flushPromises()
    expect(s.kvm.value).toEqual({ state: 'mixed' })

    dispatchPlatformEvent('install.progress', { region_id: 'r1', component: 'kata', message: 'kata-deploy 2/3' })
    dispatchPlatformEvent('install.progress', { region_id: 'r1', component: 'gvisor', message: 'runsc 1/2' })
    dispatchPlatformEvent('install.progress', { region_id: 'other', component: 'kata', message: 'not ours' })
    expect(s.kataMessage.value).toBe('kata-deploy 2/3')
    expect(s.gvisorMessage.value).toBe('runsc 1/2')
  })
})

describe('Region → Migration (useMigration)', () => {
  const migration = () => harness(emit => useMigration(ref('r1'), emit))

  it('labels the migration status, and nothing without a migration', () => {
    const m = migration()
    expect(m.statusLabel.value).toBe('')
    m.initMigrationState(null)
    expect(m.migrationStatus.value).toBe(null)
    m.initMigrationState({ migration_status: 'in_progress' })
    expect(m.statusLabel.value).toBe(i18n.global.t('settings.regionDetail.migration.statusInProgress'))
    m.initMigrationState({})
    expect(m.migrationStatus.value).toBe(null)
  })

  it('reads the new cluster\'s kubeconfig from the chosen file', async () => {
    const m = migration()
    await m.onMigrationKubeconfigChange({ target: { files: [] } })
    expect(m.migrationKubeconfigName.value).toBe('')

    const file = new File(['apiVersion: v1\nkind: Config'], 'new.yaml', { type: 'text/yaml' })
    await m.onMigrationKubeconfigChange({ target: { files: [file] } })
    expect(m.migrationKubeconfigName.value).toBe('new.yaml')
    expect(m.migrationKubeconfig.value).toBe('apiVersion: v1\nkind: Config')
  })

  it('starts a migration only when confirmed, without an empty data location', async () => {
    const m = migration()
    confirmMock.mockResolvedValueOnce(false)
    await m.startMigration()
    expect(api.startMigration).not.toHaveBeenCalled()

    m.migrationKubeconfig.value = 'kc'
    m.migrationDataLocation.value = '   '
    await m.startMigration()
    expect(api.startMigration).toHaveBeenCalledWith('r1', { kubeconfig: 'kc' })
    expect(m.migrationStatus.value).toBe('pending')
    expect(m.emit).toHaveBeenCalledWith('saved')

    api.startMigration.mockRejectedValueOnce(httpError(422, 'the cluster is in another data location'))
    await m.startMigration()
    expect(m.migrationError.value).toBe('the cluster is in another data location')
    api.startMigration.mockRejectedValueOnce(httpError(500))
    await m.startMigration()
    expect(m.migrationError.value).toBe(i18n.global.t('common.error'))
    expect(m.migrationLoading.value).toBe(false)
  })

  it('completes and rolls back a migration after a confirmation', async () => {
    const m = migration()
    confirmMock.mockResolvedValueOnce(false)
    await m.completeMigration()
    expect(api.completeMigration).not.toHaveBeenCalled()

    await m.completeMigration()
    expect(api.completeMigration).toHaveBeenCalledWith('r1')
    expect(m.migrationStatus.value).toBe('completed')

    api.completeMigration.mockRejectedValueOnce(httpError(409, 'not ready'))
    await m.completeMigration()
    expect(m.migrationError.value).toBe('not ready')

    confirmMock.mockResolvedValueOnce(false)
    await m.rollbackMigration()
    expect(api.rollbackMigration).not.toHaveBeenCalled()

    await m.rollbackMigration()
    expect(api.rollbackMigration).toHaveBeenCalledWith('r1')
    expect(m.migrationStatus.value).toBe(null)

    api.rollbackMigration.mockRejectedValueOnce(httpError(500))
    await m.rollbackMigration()
    expect(m.migrationError.value).toBe(i18n.global.t('common.error'))
    expect(m.emit).toHaveBeenCalledTimes(2)
  })

  it('completes a base-domain migration with the region the API returns', async () => {
    const m = migration()
    confirmMock.mockResolvedValueOnce(false)
    await m.completeDomainMigration()
    expect(api.completeDomainMigration).not.toHaveBeenCalled()

    api.completeDomainMigration.mockResolvedValueOnce({ data: { region: { data: { id: 'r1', is_domain_migrating: false } } } })
    await m.completeDomainMigration()
    expect(m.emit).toHaveBeenCalledWith('saved', { id: 'r1', is_domain_migrating: false })

    api.completeDomainMigration.mockRejectedValueOnce(httpError(409, 'no migration'))
    await m.completeDomainMigration()
    expect(m.migrationError.value).toBe('no migration')
    api.completeDomainMigration.mockRejectedValueOnce(httpError(500))
    await m.completeDomainMigration()
    expect(m.migrationError.value).toBe(i18n.global.t('common.error'))
  })
})

describe('Region → Knative and Redis install (useInstallPopup)', () => {
  function popup(over = {}) {
    const deps = {
      regionId: ref('r1'),
      operatorStatus: ref('not_installed'), operatorMessage: ref(''),
      servingStatus: ref('not_installed'), servingMessage: ref(''),
      nsOperator: ref(''), nsServing: ref('knative-serving'), servingVersion: ref(null),
      redisStatus: ref(null), redisAddress: ref(''), redisMode: ref(null), redisPassword: ref(''),
      t, confirm: confirmMock, ...over,
    }
    return { ...harness(() => useInstallPopup(deps)), deps }
  }

  it('labels component and Redis statuses', () => {
    const p = popup()
    expect(p.statusLabel('not_installed')).toBe('settings.regionDetail.knative.statusNotInstalled')
    expect(p.capitalize('')).toBe('')
    expect(p.redisStatusLabel(null)).toBe('settings.regionDetail.redis.statusNone')
    expect(p.redisStatusLabel('ready')).toBe('settings.regionDetail.redis.statusReady')
    expect(p.operatorReady.value).toBe(false)
  })

  it('installs the operator into the chosen namespace', async () => {
    const p = popup()
    p.openInstallPopup('operator')
    expect(p.popupNamespace.value).toBe('knative-operator')
    expect(p.popupNsCheckResult.value).toBe(null)
    expect(api.compatibleVersions).not.toHaveBeenCalled()

    api.checkNamespace.mockResolvedValueOnce({ data: { exists: true } })
    await p.checkPopupNs()
    expect(api.checkNamespace).toHaveBeenCalledWith('r1', { name: 'knative-operator' })
    expect(p.popupNsCheckResult.value).toBe('exists')
    api.checkNamespace.mockRejectedValueOnce(httpError(502))
    await p.checkPopupNs()
    expect(p.popupNsCheckResult.value).toBe(null)

    p.popupNamespace.value = 'kn-op'
    await p.confirmInstallPopup()
    expect(api.installOperator).toHaveBeenCalledWith('r1', { namespace: 'kn-op' })
    expect(p.deps.nsOperator.value).toBe('kn-op')
    expect(p.deps.operatorStatus.value).toBe('installing')
    expect(p.installing.value.operator).toBe(true)
    expect(p.installPopupOpen.value).toBe(false)
  })

  it('installs Serving with the newest compatible version and shows a refusal', async () => {
    api.compatibleVersions.mockResolvedValue({ data: { versions: ['1.21', '1.20'] } })
    const p = popup()
    p.openInstallPopup('serving')
    await flushPromises()
    expect(p.popupVersion.value).toBe('1.21')

    api.installServing.mockRejectedValueOnce(httpError(422, 'the operator is not ready'))
    await p.confirmInstallPopup()
    expect(p.popupError.value).toBe('the operator is not ready')
    expect(p.installPopupOpen.value).toBe(true)

    await p.confirmInstallPopup()
    expect(api.installServing).toHaveBeenLastCalledWith('r1', { namespace: 'knative-serving', version: '1.21' })
    expect(p.deps.servingVersion.value).toBe('1.21')
    expect(p.deps.servingStatus.value).toBe('installing')

    api.compatibleVersions.mockRejectedValueOnce(httpError(502))
    await p.loadVersions()
    expect(p.compatibleVersions.value).toEqual([])
  })

  it('refuses a Serving upgrade without a newer version', async () => {
    api.compatibleVersions.mockResolvedValue({ data: { versions: ['1.20'] } })
    const p = popup({ servingStatus: ref('ready'), servingVersion: ref('v1.20.1') })
    p.openInstallPopup('serving', 'upgrade')
    await flushPromises()
    expect(p.popupNsCheckResult.value).toBe('available')
    expect(p.compatibleVersions.value).toEqual([])
    expect(p.popupVersion.value).toBe(null)

    await p.confirmInstallPopup()
    expect(api.upgradeServing).not.toHaveBeenCalled()
    expect(p.popupError.value).toBe('settings.regionDetail.installPopup.noUpgrade')
    expect(p.popupSubmitting.value).toBe(false)
  })

  it('uninstalls a component after a confirmation and shows why it failed', async () => {
    const p = popup({ operatorStatus: ref('ready'), servingStatus: ref('ready') })
    confirmMock.mockResolvedValueOnce(false)
    await p.confirmUninstallComponent('serving')
    expect(api.uninstallServing).not.toHaveBeenCalled()

    await p.confirmUninstallComponent('serving')
    expect(api.uninstallServing).toHaveBeenCalledWith('r1')
    expect(p.deps.servingStatus.value).toBe('uninstalling')

    api.uninstallOperator.mockRejectedValueOnce(httpError(409, 'Serving is still installed'))
    await p.confirmUninstallComponent('operator')
    await flushPromises()
    expect(p.deps.operatorStatus.value).toBe('error')
    expect(p.deps.operatorMessage.value).toBe('Serving is still installed')
  })

  it('polls the component status while something installs', async () => {
    vi.useFakeTimers()
    api.componentStatus.mockResolvedValue({ data: { operator: { status: 'ready' }, serving: { status: 'installing', message: 'step 2' }, redis: { status: 'ready' } } })
    const p = popup({ operatorStatus: ref('installing') })
    await vi.advanceTimersByTimeAsync(5000)
    expect(api.componentStatus).toHaveBeenCalledWith('r1')
    expect(p.deps.operatorStatus.value).toBe('ready')
    expect(p.deps.servingMessage.value).toBe('step 2')
    expect(p.deps.redisStatus.value).toBe('ready')

    // Serving still installs: polling goes on; a failed poll is ignored
    api.componentStatus.mockRejectedValueOnce(new Error('down'))
    await vi.advanceTimersByTimeAsync(5000)
    expect(api.componentStatus).toHaveBeenCalledTimes(2)

    api.componentStatus.mockResolvedValue({ data: { serving: { status: 'ready' }, redis: null } })
    await vi.advanceTimersByTimeAsync(5000)
    expect(p.deps.servingStatus.value).toBe('ready')
    expect(p.deps.redisStatus.value).toBe('ready')
    await vi.advanceTimersByTimeAsync(10000)
    expect(api.componentStatus).toHaveBeenCalledTimes(3)
  })

  it('follows the Knative, Redis and progress events of its own region', async () => {
    const p = popup({ servingStatus: ref('installing') })
    api.componentStatus.mockResolvedValue({ data: { serving: { status: 'ready', message: '' } } })
    p.installing.value = { serving: true }

    dispatchPlatformEvent('knative.serving.installed', { region_id: 'other' })
    dispatchPlatformEvent('knative.serving.installed', { region_id: 'r1' })
    await flushPromises()
    expect(p.deps.servingStatus.value).toBe('ready')
    expect(p.installing.value.serving).toBe(false)

    dispatchPlatformEvent('knative.operator.install_failed', { region_id: 'r1', error: 'image pull' })
    expect(p.deps.operatorStatus.value).toBe('error')
    expect(p.deps.operatorMessage.value).toBe('image pull')

    api.redisStatus.mockResolvedValueOnce({ data: { knative_redis_status: null, redis_address: null, redis_mode: 'external' } })
    dispatchPlatformEvent('redis.removed', { region_id: 'r1' })
    dispatchPlatformEvent('redis.removed', { region_id: 'other' })
    await flushPromises()
    expect(api.redisStatus).toHaveBeenCalledTimes(1)
    expect(p.deps.redisMode.value).toBe('external')

    dispatchPlatformEvent('install.progress', { region_id: 'r1', component: 'operator', message: 'op 1/2' })
    dispatchPlatformEvent('install.progress', { region_id: 'r1', component: 'serving', message: 'sv 1/2' })
    dispatchPlatformEvent('install.progress', { region_id: 'other', component: 'serving', message: 'x' })
    expect(p.deps.operatorMessage.value).toBe('op 1/2')
    expect(p.deps.servingMessage.value).toBe('sv 1/2')
  })

  it('tests, saves and removes the region Redis', async () => {
    const p = popup({ redisMode: ref('external'), redisAddress: ref('redis:6379'), redisPassword: ref('pw') })
    p.initRedisPopup()
    expect(p.popupRedisMode.value).toBe('external')
    expect(p.popupRedisAddress.value).toBe('redis:6379')

    api.testRedis.mockResolvedValueOnce({ data: { reachable: true } })
    await p.testPopupRedis()
    expect(api.testRedis).toHaveBeenCalledWith('r1', { address: 'redis:6379', password: 'pw' })
    expect(p.popupRedisTestResult.value).toBe('ok')
    api.testRedis.mockRejectedValueOnce(httpError(502))
    await p.testPopupRedis()
    expect(p.popupRedisTestResult.value).toBe('fail')

    p.onPopupRedisModeChange('managed')
    expect(p.popupRedisTestResult.value).toBe(null)
    api.configureRedis.mockResolvedValueOnce({ data: { region: { data: { knative_redis_status: 'installing', redis_address: null } } } })
    await p.saveRedis()
    expect(api.configureRedis).toHaveBeenCalledWith('r1', { mode: 'managed', address: undefined, password: 'pw' })
    expect(p.deps.redisStatus.value).toBe('installing')
    expect(p.deps.redisMode.value).toBe('managed')

    api.configureRedis.mockRejectedValueOnce(httpError(422, 'address required'))
    p.onPopupRedisModeChange('external')
    await p.saveRedis()
    expect(p.redisError.value).toBe('address required')

    confirmMock.mockResolvedValueOnce(false)
    await p.removeRedis()
    expect(api.removeRedis).not.toHaveBeenCalled()
    await p.removeRedis()
    expect(p.deps.redisStatus.value).toBe('uninstalling')
    api.removeRedis.mockRejectedValueOnce(httpError(500))
    await p.removeRedis()
    expect(p.redisError.value).toBe('common.error')
  })
})

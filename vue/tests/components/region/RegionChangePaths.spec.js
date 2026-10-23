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
import RedisSection from '@/components/region/RedisSection.vue'
import RegionSettingsSection from '@/components/region/RegionSettingsSection.vue'
import RegionMonitoringSection from '@/components/region/RegionMonitoringSection.vue'
import FunctionTlsSection from '@/components/region/FunctionTlsSection.vue'
import RegistryTab from '@/components/region/RegistryTab.vue'
import KataShimSelector from '@/components/region/KataShimSelector.vue'
import MigrationTab from '@/components/region/MigrationTab.vue'
import InstallPopup from '@/components/region/InstallPopup.vue'
import { useInstallPopup } from '@/composables/useInstallPopup'
import { useMigration } from '@/composables/useMigration'
import RegionsList from '@/components/settings/RegionsList.vue'
import { usePermissionsStore } from '@/stores/permissions'
import { makeI18n, freshPinia } from '@/test-support/helpers'

const i18n = makeI18n()
const mounted = []

function mountIt(component, props) {
  const w = mount(component, { props, global: { plugins: [freshPinia(), i18n] } })
  mounted.push(w)
  return w
}

/** A composable with lifecycle hooks, inside a component */
function harness(useFn) {
  let exposed
  const Comp = defineComponent({
    emits: ['saved'],
    setup(_, { emit }) {
      exposed = useFn(emit)
      return () => h('div')
    },
  })
  mounted.push(mount(Comp, { global: { plugins: [freshPinia(), i18n] } }))
  return exposed
}

const region = (extra = {}) => ({
  id: 'r1', name: 'eu-1', knative_settings: { request_timeout: 300 }, monitoring: { prometheus_managed: true, loki_managed: true },
  registry_mode: null, registry_status: null, ...extra,
})

beforeEach(() => {
  for (const key of Object.keys(api)) api[key].mockReset().mockResolvedValue({ data: {} })
  confirmMock.mockClear()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Region → Redis', () => {
  it('offers in_cluster, the mode the API takes', async () => {
    const w = mountIt(RedisSection, { redisStatus: null })
    const card = w.findAll('button').find(b => b.text().includes('In-cluster Redis'))
    await card.trigger('click')
    expect(w.emitted('update:popupRedisMode')[0]).toEqual(['in_cluster'])

    await w.setProps({ popupRedisMode: 'in_cluster' })
    expect(w.find('input[type="text"]').exists()).toBe(true)
  })
})

describe('Region → Serving → Upgrade', () => {
  it('offers only the next minor version and calls upgrade-serving', async () => {
    api.compatibleVersions.mockResolvedValue({ data: { versions: ['1.21', '1.20', '1.19'] } })
    const servingStatus = ref('ready')
    const popup = harness(emit => useInstallPopup({
      regionId: ref('r1'), isEdit: ref(true), createdRegion: ref(null), emit,
      operatorStatus: ref('ready'), operatorMessage: ref(''), servingStatus, servingMessage: ref(''),
      nsOperator: ref('knative-operator'), nsServing: ref('knative-serving'), servingVersion: ref('1.20.2'),
      redisStatus: ref(null), redisAddress: ref(''), redisMode: ref(null), redisPassword: ref(''),
      t: k => k, confirm: confirmMock,
    }))

    popup.openInstallPopup('serving', 'upgrade')
    await flushPromises()
    expect(popup.compatibleVersions.value).toEqual(['1.21'])
    expect(popup.popupVersion.value).toBe('1.21')

    await popup.confirmInstallPopup()
    expect(api.upgradeServing).toHaveBeenCalledWith('r1', { version: '1.21' })
    expect(api.installServing).not.toHaveBeenCalled()
    expect(servingStatus.value).toBe('installing')
    expect(popup.installPopupOpen.value).toBe(false)
  })

  it('says so when the cluster supports no newer version', async () => {
    const w = mountIt(InstallPopup, { open: true, component: 'serving', mode: 'upgrade', namespace: 'knative-serving', compatibleVersions: [], version: null })
    expect(w.find('[data-testid="no-upgrade"]').exists()).toBe(true)
    const confirm = w.findAll('button').find(b => b.text() === 'Upgrade')
    expect(confirm.attributes('disabled')).toBeDefined()
  })
})

describe('Region → General → Settings', () => {
  it('saves request_timeout, agent_ws_url, cert_manager_cluster_issuer and ingress_trusted_ips', async () => {
    api.updateSettings.mockResolvedValue({ data: { region: region({ knative_settings: { request_timeout: 900 } }) } })
    const w = mountIt(RegionSettingsSection, { region: region({ knative_settings: { request_timeout: 300, ingress_trusted_ips: ['10.0.0.0/24'] } }) })
    expect(w.find('#region-trusted-ips').element.value).toBe('10.0.0.0/24')

    await w.find('#region-request-timeout').setValue('900')
    await w.find('#region-agent-url').setValue('wss://runr.internal.example.test')
    await w.find('#region-trusted-ips').setValue('10.0.0.0/24, 192.0.2.10')
    await w.find('[data-testid="save-region-settings"]').trigger('click')
    await flushPromises()

    expect(api.updateSettings).toHaveBeenCalledWith('r1', {
      request_timeout: 900, agent_ws_url: 'wss://runr.internal.example.test', cert_manager_cluster_issuer: null,
      ingress_trusted_ips: ['10.0.0.0/24', '192.0.2.10'], internal_auth: 'enforce',
    })
    expect(w.emitted('saved')[0][0].knative_settings.request_timeout).toBe(900)
    expect(w.text()).toContain('Settings saved.')
  })

  it('enforces the internal network unless audit is chosen', async () => {
    api.updateSettings.mockResolvedValue({ data: { region: region({ knative_settings: { internal_auth: 'audit' } }) } })
    // a region of an earlier release has no internal_auth: it enforces
    const w = mountIt(RegionSettingsSection, { region: region({ knative_settings: { request_timeout: 300 } }) })
    expect(w.find('[data-testid="region-internal-auth"]').element.value).toBe('enforce')

    await w.find('[data-testid="region-internal-auth"]').setValue('audit')
    await w.find('[data-testid="save-region-settings"]').trigger('click')
    await flushPromises()

    expect(api.updateSettings.mock.calls[0][1].internal_auth).toBe('audit')
    expect(mountIt(RegionSettingsSection, { region: region({ knative_settings: { internal_auth: 'audit' } }) })
      .find('[data-testid="region-internal-auth"]').element.value).toBe('audit')
  })
})

describe('Region → General → Monitoring', () => {
  it('points the region at an existing Prometheus and back at RunR\'s own', async () => {
    const w = mountIt(RegionMonitoringSection, { region: region({ monitoring: { prometheus_managed: false, prometheus_url: 'http://p.monitoring.svc:9090', loki_managed: true } }) })
    expect(w.text()).toContain('Prometheus: http://p.monitoring.svc:9090')

    const prometheus = w.findAll('input[type="text"]')[0]
    expect(prometheus.element.value).toBe('http://p.monitoring.svc:9090')
    await prometheus.setValue('')
    await w.find('[data-testid="save-region-monitoring"]').trigger('click')
    await flushPromises()

    expect(api.updateMonitoring).toHaveBeenCalledWith('r1', { prometheus_url: null, loki_url: null, loki_org_id: null })
  })
})

describe('Region → General → Function TLS', () => {
  it('switches function TLS off after a confirmation', async () => {
    const w = mountIt(FunctionTlsSection, { regionId: 'r1', mode: 'custom', expiresAt: '2027-01-14T00:00:00Z' })

    await w.find('[data-testid="remove-function-tls"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalled()
    expect(api.removeFunctionTls).toHaveBeenCalledWith('r1')
    expect(w.find('[data-testid="remove-function-tls"]').exists()).toBe(false)
  })
})

describe('Region → Registry', () => {
  it('sets up a registry for a region without one', async () => {
    api.downloadKubeconfig.mockResolvedValue({ data: 'apiVersion: v1' })
    api.previewStorageClasses.mockResolvedValue({ data: { storageClasses: [{ name: 'fast', is_default: true }] } })
    api.configureRegistry.mockResolvedValue({ data: { region: region({ registry_mode: 'internal', registry_status: 'installing' }) } })
    const w = mountIt(RegistryTab, { region: region() })
    await flushPromises()

    await w.find('[data-testid="setup-registry"]').trigger('click')
    await flushPromises()

    expect(api.configureRegistry).toHaveBeenCalledWith('r1', { registry_mode: 'internal', storage_type: 'pvc', pvc_config: { storageClass: 'fast', size: '10Gi' } })
    expect(w.emitted('saved')[0][0].registry_status).toBe('installing')
  })

  it('removes a registry and replaces an external one', async () => {
    const w = mountIt(RegistryTab, { region: region({ registry_mode: 'external', registry_status: 'ready', registry_address: 'registry.example.test' }) })
    expect(w.find('[data-testid="setup-registry"]').exists()).toBe(false)

    await w.find('[data-testid="replace-registry"]').trigger('click')
    expect(w.find('[data-testid="setup-registry"]').exists()).toBe(true)

    await w.setProps({ region: region({ registry_mode: 'internal', registry_status: 'ready' }) })
    await w.find('[data-testid="remove-registry"]').trigger('click')
    await flushPromises()
    expect(api.removeRegistry).toHaveBeenCalledWith('r1')
  })
})

describe('Region → Isolation → Kata hypervisors', () => {
  it('keeps the installed hypervisors and adds others', async () => {
    const shims = {
      clh: { label: 'Cloud Hypervisor', category: 'general', available: true },
      qemu: { label: 'QEMU', category: 'general', available: true },
    }
    const w = mountIt(KataShimSelector, { shims, modelValue: ['clh'], locked: ['clh'] })
    const [clh, qemu] = w.findAll('button')

    await clh.trigger('click')
    expect(w.emitted('update:modelValue')).toBeUndefined()
    await qemu.trigger('click')
    expect(w.emitted('update:modelValue')[0]).toEqual([['clh', 'qemu']])
  })
})

describe('Regions list', () => {
  it('opens a region whose cluster does not answer: its Migration tab moves it elsewhere', async () => {
    const pinia = freshPinia()
    const w = mount(RegionsList, {
      props: { regions: [{ id: 'r1', name: 'eu-1', cluster_status: 'unreachable', is_synced: false, is_demo: false, project_count: 0 }] },
      global: { plugins: [pinia, i18n] },
    })
    mounted.push(w)
    usePermissionsStore().apply({ permissions: ['regions.manage', 'regions.view'] })
    await flushPromises()

    await w.find('button[aria-label="Edit"]').trigger('click')
    expect(w.emitted('edit')[0][0].id).toBe('r1')
  })
})

describe('Region → Migration', () => {
  it('sends the new cluster\'s data location', async () => {
    const m = harness(emit => useMigration(ref('r1'), emit))
    m.migrationKubeconfig.value = 'apiVersion: v1'
    m.migrationDataLocation.value = ' EU '

    await m.startMigration()

    expect(api.startMigration).toHaveBeenCalledWith('r1', { kubeconfig: 'apiVersion: v1', data_location: 'EU' })
  })

  it('shows a base-domain migration and completes it', async () => {
    const w = mountIt(MigrationTab, {
      region: { id: 'r1', is_domain_migrating: true, domain_migration_target: 'eu-1.new.test', dns_instruction: { target: '203.0.113.7' } },
    })
    expect(w.find('[data-testid="domain-migration"]').text()).toContain('*.eu-1.new.test → 203.0.113.7')
    await w.find('[data-testid="migration-data-location"]').setValue('EU')
    expect(w.emitted('update:data-location')[0]).toEqual(['EU'])

    await w.find('[data-testid="domain-migration"] button').trigger('click')
    expect(w.emitted('complete-domain')).toHaveLength(1)

    const m = harness(emit => useMigration(ref('r1'), emit))
    await m.completeDomainMigration()
    expect(api.completeDomainMigration).toHaveBeenCalledWith('r1')
  })

  it('loads what the move did with each project while it can be rolled back', async () => {
    const projects = [
      { organization: null, project_id: 'p1', project: 'orders', outcome: 'building', version: 5, deployment_status: null, message: 'Moving to the new cluster of the region: v5 is built again there; deployed there when it is ready.' },
      { organization: 'acme', project_id: 'p2', project: 'shop', outcome: 'not_moved', version: 1, deployment_status: 'shut_down', message: 'its image myapp:1.0 was uploaded into the registry of the cluster the region left' },
    ]
    api.migrationStatus.mockResolvedValue({ data: { migration_status: 'dns_check', projects } })
    const m = harness(emit => useMigration(ref('r1'), emit))

    m.initMigrationState({ id: 'r1', migration_status: 'dns_check' })
    await flushPromises()
    expect(api.migrationStatus).toHaveBeenCalledWith('r1')
    expect(m.migrationProjects.value).toEqual(projects)

    // No move to roll back: nothing to ask for
    api.migrationStatus.mockClear()
    m.initMigrationState({ id: 'r1', migration_status: 'completed' })
    await flushPromises()
    expect(api.migrationStatus).not.toHaveBeenCalled()
    expect(m.migrationProjects.value).toEqual([])

    // A report that cannot be read leaves the tab working
    m.migrationStatus.value = 'failed'
    api.migrationStatus.mockRejectedValue(new Error('502'))
    await m.loadMigrationReport()
    expect(m.migrationProjects.value).toEqual([])

    // A rollback ends the report
    m.migrationProjects.value = projects
    await m.rollbackMigration()
    expect(m.migrationProjects.value).toEqual([])
  })

  it('lists the projects of the move with their outcome and reason', async () => {
    const w = mountIt(MigrationTab, {
      region: { id: 'r1' }, migrationStatus: 'dns_check',
      migrationProjects: [
        { organization: null, project_id: 'p1', project: 'orders', outcome: 'deployed', version: 5, message: 'v5 was built and its deploy started.' },
        { organization: 'acme', project_id: 'p2', project: 'shop', outcome: 'not_moved', version: 1, message: 'its image myapp:1.0 was uploaded into the registry of the cluster the region left' },
        { organization: 'acme', project_id: 'p3', project: 'idle', outcome: 'cancelled', version: null, message: null },
      ],
    })

    const rows = w.findAll('[data-testid="migration-projects"] li')
    expect(rows).toHaveLength(3)
    expect(rows[0].text()).toContain('Built and moved')
    expect(rows[0].text()).toContain('orders v5')
    expect(rows[1].text()).toContain('Not moved')
    expect(rows[1].text()).toContain('shop v1 · acme')
    expect(rows[1].text()).toContain('uploaded into the registry of the cluster the region left')

    await w.find('[data-testid="migration-projects-refresh"]').trigger('click')
    expect(w.emitted('refresh-projects')).toHaveLength(1)
  })

  it('lists the environments of the move as rows of their own', () => {
    const w = mountIt(MigrationTab, {
      region: { id: 'r1' }, migrationStatus: 'dns_check',
      migrationProjects: [
        { organization: null, project_id: 'p1', project: 'orders', environment: null, outcome: 'moved', version: 5, message: null },
        { organization: null, project_id: 'p1', project: 'orders', environment: 'staging', outcome: 'building', version: 6, message: 'Waits for v6 to be built into the registry of eu (the version\'s code, the same runtime base); deployed when it is ready.' },
      ],
    })

    const rows = w.findAll('[data-testid="migration-projects"] li')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).not.toContain('(staging)')
    expect(rows[1].attributes('data-environment')).toBe('staging')
    expect(rows[1].text()).toContain('orders (staging) v6')
    expect(rows[1].text()).toContain('built into the registry of eu')
  })
})

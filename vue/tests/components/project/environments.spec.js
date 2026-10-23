import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { environmentsApi } from '@/api/environments'
import EnvironmentSwitcher from '@/components/project/EnvironmentSwitcher.vue'
import PromoteDialog from '@/components/project/PromoteDialog.vue'
import PromotionList from '@/components/project/PromotionList.vue'
import RollbackModal from '@/components/project/RollbackModal.vue'
import DeploymentTable from '@/components/DeploymentTable.vue'
import DeployModal from '@/components/DeployModal.vue'
import { useToast } from '@/composables/useToast'
import { dispatchPlatformEvent } from '@/composables/useJobEvents'
import ApprovalsView from '@/views/ApprovalsView.vue'
import ProjectEnvironments from '@/components/project/ProjectEnvironments.vue'
import EnvironmentFormModal from '@/components/project/EnvironmentFormModal.vue'
import WarmerSchedule from '@/components/project/WarmerSchedule.vue'
import DeployStrategyModal from '@/components/project/DeployStrategyModal.vue'
import { projectsApi } from '@/api/projects'
import { DEFAULTS } from '@/utils/deployStrategy'
import { withPlugins } from '../../helpers'

// Environments and promotion (docs/environments.md) and the rollback with its config (#626).

const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setTimeout(r, 0)) }
const ok = (data) => Promise.resolve({ data })
const fail = (status, data) => Promise.reject(Object.assign(new Error('x'), { response: { status, data } }))

const environments = () => [
  { id: 'e-dev', name: 'dev', is_primary: false, region: { id: 'r1', name: 'eu' }, url: 'https://shop-dev.eu.example', deployment: { id: 'd-dev', status: 'active', version: { id: 'v3', number: 3, label: 'v3' } } },
  { id: 'e-staging', name: 'staging', is_primary: false, region: { id: 'r1', name: 'eu' }, url: 'https://shop-staging.eu.example', deployment: { id: 'd-st', status: 'active', version: { id: 'v2', number: 2, label: 'v2' } } },
  { id: 'e-prod', name: 'production', is_primary: true, requires_approval: true, region: { id: 'r1', name: 'eu' }, url: 'https://shop.eu.example', deployment: { id: 'd-pr', status: 'active', version: { id: 'v1', number: 1, label: 'v1' } } },
]

afterEach(() => vi.restoreAllMocks())

describe('EnvironmentSwitcher', () => {
  it('lists production and the environments with their versions, and switches', async () => {
    const w = mount(EnvironmentSwitcher, { props: { environments: environments(), modelValue: null }, global: withPlugins().global })

    expect(w.findAll('button').map(b => b.text())).toEqual(['devv3', 'stagingv2', 'productionv1'])
    expect(w.find('[data-testid="environment-production"]').attributes('aria-pressed')).toBe('true')
    expect(w.find('[data-testid="environment-summary"]').text()).toContain('https://shop.eu.example')

    await w.find('[data-testid="environment-staging"]').trigger('click')
    expect(w.emitted('update:modelValue')[0]).toEqual(['staging'])

    await w.setProps({ modelValue: 'staging' })
    expect(w.find('[data-testid="environment-summary"]').text()).toContain('https://shop-staging.eu.example')
    await w.find('[data-testid="environment-production"]').trigger('click')
    expect(w.emitted('update:modelValue')[1]).toEqual([null])
  })

  it('stays hidden while production is the only environment', () => {
    const w = mount(EnvironmentSwitcher, { props: { environments: [environments()[2]] }, global: withPlugins().global })
    expect(w.find('[data-testid="environment-switcher"]').exists()).toBe(false)
  })
})

describe('PromoteDialog', () => {
  const preview = {
    source: { environment: 'staging' }, target: { environment: 'production' },
    code: { from: { label: 'v1', image: 'ghcr.io/acme/shop:1.0' }, to: { label: 'v2', image: 'ghcr.io/acme/shop:1.1' }, changes: true },
    config: [
      { field: 'image', from: 'ghcr.io/acme/shop:1.0', to: 'ghcr.io/acme/shop:1.1' },
      { field: 'env_vars', added: ['FEATURE_X'], removed: ['OLD'], changed: ['GREETING'] },
      { field: 'min_scale', from: 0, to: 2 },
    ],
    environment_differences: [{ field: 'secrets', added: ['API_KEY'], removed: [], changed: [] }],
    requires_approval: true, can_promote: true, problem: null,
  }

  it('shows what will change by name and asks for approval', async () => {
    const get = vi.spyOn(environmentsApi, 'promotePreview').mockReturnValue(ok({ data: preview }))
    const w = mount(PromoteDialog, { props: { projectId: 'p1', source: environments()[1], environments: environments() }, global: withPlugins().global })
    await flush()

    expect(get).toHaveBeenCalledWith('p1', 'staging', 'production')
    expect(w.find('[data-testid="promote-code"]').text()).toContain('v1')
    expect(w.find('[data-testid="promote-code"]').text()).toContain('ghcr.io/acme/shop:1.1')
    const env = w.find('[data-testid="promote-change-env_vars"]').text()
    expect(env).toContain('FEATURE_X')
    expect(env).toContain('OLD')
    expect(env).toContain('GREETING')
    expect(w.find('[data-testid="promote-change-min_scale"]').text()).toContain('0 → 2')
    expect(w.find('[data-testid="promote-approval"]').text()).toContain('need approval')
    expect(w.find('[data-testid="promote-submit"]').text()).toBe('Request approval')
  })

  it('requests the promotion with a message', async () => {
    vi.spyOn(environmentsApi, 'promotePreview').mockReturnValue(ok({ data: preview }))
    const post = vi.spyOn(environmentsApi, 'promote').mockReturnValue(ok({ data: { id: 'pr1', status: 'pending_approval', target: 'production' } }))
    const w = mount(PromoteDialog, { props: { projectId: 'p1', source: environments()[1], environments: environments() }, global: withPlugins().global })
    await flush()

    await w.find('textarea').setValue(' release 1.1 ')
    await w.find('[data-testid="promote-submit"]').trigger('click')
    await flush()

    expect(post).toHaveBeenCalledWith('p1', 'staging', { to: 'production', message: 'release 1.1' })
    expect(w.emitted('promoted')[0][0].status).toBe('pending_approval')
  })

  it('cannot submit when nothing runs in the source', async () => {
    vi.spyOn(environmentsApi, 'promotePreview').mockReturnValue(ok({ data: { ...preview, config: [], can_promote: false, problem: 'Nothing runs in dev: deploy a version there first.' } }))
    const w = mount(PromoteDialog, { props: { projectId: 'p1', source: environments()[0], environments: environments() }, global: withPlugins().global })
    await flush()

    expect(w.text()).toContain('Nothing runs in dev')
    expect(w.find('[data-testid="promote-submit"]').attributes('disabled')).toBeDefined()
  })
})

describe('PromotionList (approvals inbox)', () => {
  const pending = (over = {}) => ({
    id: 'pr1', project_id: 'p1', project: { id: 'p1', name: 'shop' }, source: 'staging', target: 'production',
    version: { id: 'v2', label: 'v2' }, status: 'pending_approval', requested_by: 'Ada', created_at: '2026-10-21T10:00:00Z',
    can_approve: true, can_cancel: false, ...over,
  })

  it('approves with a comment', async () => {
    useToast().toasts.value = []
    const approve = vi.spyOn(environmentsApi, 'approve').mockReturnValue(ok({ data: pending({ status: 'deploying', can_approve: false }) }))
    const w = mount(PromotionList, { props: { promotions: [pending()], showProject: true }, global: withPlugins().global })

    expect(w.text()).toContain('shop')
    await w.find('[data-testid="promotion-comment-pr1"]').setValue('ok to ship')
    await w.find('[data-testid="promotion-approve-pr1"]').trigger('click')
    await flush()

    expect(approve).toHaveBeenCalledWith('p1', 'pr1', 'ok to ship')
    expect(w.emitted('changed')).toHaveLength(1)
    expect(useToast().toasts.value.at(-1).message).toContain('v2 goes to production')
  })

  it('shows a refusal, rejects, and lets the requester withdraw', async () => {
    vi.spyOn(environmentsApi, 'approve').mockImplementation(() => fail(403, { message: 'A promotion is approved by someone other than the one who requested it.', code: 'self_approval' }))
    const reject = vi.spyOn(environmentsApi, 'reject').mockReturnValue(ok({ data: pending({ status: 'rejected' }) }))
    const cancel = vi.spyOn(environmentsApi, 'cancel').mockReturnValue(ok({ data: pending({ id: 'pr2', status: 'cancelled' }) }))
    const w = mount(PromotionList, {
      props: { promotions: [pending(), pending({ id: 'pr2', can_approve: false, can_cancel: true })] },
      global: withPlugins().global,
    })

    await w.find('[data-testid="promotion-approve-pr1"]').trigger('click')
    await flush()
    expect(w.text()).toContain('someone other than the one who requested it')

    await w.find('[data-testid="promotion-reject-pr1"]').trigger('click')
    await flush()
    expect(reject).toHaveBeenCalledWith('p1', 'pr1', null)

    expect(w.find('[data-testid="promotion-approve-pr2"]').exists()).toBe(false)
    await w.find('[data-testid="promotion-cancel-pr2"]').trigger('click')
    await flush()
    expect(cancel).toHaveBeenCalledWith('p1', 'pr2')
  })
})

describe('ApprovalsView', () => {
  // docs/environments.md#notifications: a promotion requested, decided or ended elsewhere reaches
  // an open inbox through the environment.promotion broadcast
  it('reloads the inbox when a promotion changes anywhere, and stops listening once closed', async () => {
    const inbox = vi.spyOn(environmentsApi, 'inbox').mockImplementation(() => ok({ data: [] }))
    const w = mount(ApprovalsView, { global: { ...withPlugins().global, stubs: { AppHeader: true, PromotionList: true } } })
    await flush()
    expect(inbox).toHaveBeenCalledTimes(1)

    dispatchPlatformEvent('environment.promotion', { promotion_id: 'pr1', event: 'pending_approval', status: 'pending_approval' })
    await flush()
    expect(inbox).toHaveBeenCalledTimes(2)
    expect(inbox).toHaveBeenLastCalledWith({ status: 'pending_approval' })

    w.unmount()
    dispatchPlatformEvent('environment.promotion', { promotion_id: 'pr1', event: 'approved', status: 'deploying' })
    await flush()
    expect(inbox).toHaveBeenCalledTimes(2)
  })
})

describe('Rollback (#626)', () => {
  const deployments = [
    { id: 'd2', status: 'active', version: { id: 'v2', label: 'v2' }, environment: 'production', created_at: '2026-10-21T10:00:00Z' },
    { id: 'd1', status: 'shut_down', version: { id: 'v1', label: 'v1' }, environment: 'production', config_source: 'restored', created_at: '2026-10-20T10:00:00Z' },
  ]

  it('offers the rollback on earlier deployments and marks restored ones', async () => {
    const w = mount(DeploymentTable, { props: { deployments, project: { id: 'p1', config: { trigger_type: 'http' } } }, global: withPlugins().global })
    const rows = w.findAll('tbody tr')

    expect(rows[0].find('[data-testid="deployment-rollback"]').exists()).toBe(false)
    expect(rows[1].find('[data-testid="deployment-restored"]').exists()).toBe(true)
    await rows[1].find('[data-testid="deployment-rollback"]').trigger('click')
    expect(w.emitted('rollback')[0][0].id).toBe('d1')
  })

  it('restores the config unless "keep the current configuration" is checked', async () => {
    const w = mount(RollbackModal, { props: { deployment: deployments[1] }, global: withPlugins().global })
    expect(w.text()).toContain('Roll back to v1')
    await w.find('[data-testid="rollback-confirm"]').trigger('click')
    expect(w.emitted('confirm')[0][0]).toEqual({ keepCurrentConfig: false })

    await w.find('[data-testid="rollback-keep-config"]').setValue(true)
    await w.find('[data-testid="rollback-confirm"]').trigger('click')
    expect(w.emitted('confirm')[1][0]).toEqual({ keepCurrentConfig: true })
  })

  it('deploys an older version to the chosen environment, with its config when asked', async () => {
    const versions = [{ id: 'v3', label: 'v3' }, { id: 'v2', label: 'v2' }, { id: 'v1', label: 'v1' }]
    const w = mount(DeployModal, { props: { projectName: 'shop', versions, environments: environments(), environment: 'staging' }, global: withPlugins().global })

    expect(w.find('[data-testid="deploy-environment"]').element.value).toBe('staging')
    expect(w.find('[data-testid="deploy-restore-config"]').exists()).toBe(false)

    await w.find('input[value="v1"]').setValue(true)
    const restore = w.find('[data-testid="deploy-restore-config"] input')
    expect(restore.exists()).toBe(true)
    await restore.setValue(true)
    await w.find('button.btn-primary').trigger('click')
    expect(w.emitted('deploy')[0][0]).toEqual({ versionId: 'v1', strategy: null, environment: 'staging', restoreConfig: true })
  })
})

// Each environment's deploy strategy opens from its card (docs/deploy-strategies.md#environments)
describe('ProjectEnvironments: deploy strategies', () => {
  it('names the strategy of each environment and opens its settings', async () => {
    const list = environments().map(e => ({ ...e, deploy_strategy: e.name === 'staging' ? 'canary' : 'direct' }))
    vi.spyOn(environmentsApi, 'list').mockReturnValue(ok({ data: list, abilities: { manage: true, promote: true, approve: false } }))
    vi.spyOn(environmentsApi, 'promotions').mockReturnValue(ok({ data: [] }))
    const load = vi.spyOn(projectsApi, 'deployStrategy').mockReturnValue(ok({ data: { ...structuredClone(DEFAULTS), strategy: 'canary', eligible: true, trigger_type: 'http', tracing: false, defaults: structuredClone(DEFAULTS), environment: 'staging', own: { strategy: 'canary' }, inherited: false, project: 'direct' } }))
    const project = { id: 'p1', name: 'shop', service_type: 'faas', deploy_strategy: 'direct', config: { trigger_type: 'http' } }
    const w = mount(ProjectEnvironments, { props: { project }, global: withPlugins().global })
    await flush()

    expect(w.find('[data-testid="environment-strategy-staging"]').text()).toBe('Deploy strategy: Canary')
    expect(w.find('[data-testid="environment-strategy-production"]').text()).toBe('Deploy strategy: Direct')

    await w.find('[data-testid="environment-strategy-staging"]').trigger('click')
    await flush()
    expect(w.findComponent(DeployStrategyModal).props('environment')).toBe('staging')
    expect(load).toHaveBeenCalledWith('p1', 'staging')

    // saved: the cards are read again
    w.findComponent(DeployStrategyModal).vm.$emit('saved', { strategy: 'direct', environment: 'staging' })
    await flush()
    expect(environmentsApi.list).toHaveBeenCalledTimes(2)
    expect(w.emitted('changed')).toHaveLength(1)

    await w.findComponent(DeployStrategyModal).vm.$emit('close')
    await flush()
    expect(w.findComponent(DeployStrategyModal).exists()).toBe(false)
  })
})

// The timed warmer of an environment (docs/scaling.md#timed-warmer): the project's schedule, one of
// its own, or none
describe('Environments: timed warmer', () => {
  const project = { id: 'p1', name: 'shop', service_type: 'caas', knative_region_id: 'r1', deploy_strategy: 'direct',
    config: { trigger_type: 'http', max_scale: 3, scale_schedule: [{ cron: '* * * * *', min_scale: 1 }] } }

  it('the form follows the project, sets a schedule of its own or none, and keeps what it does not show', async () => {
    vi.spyOn(projectsApi, 'scaleSchedulePreview').mockReturnValue(ok({ timezone: 'Europe/Istanbul', timeline: [], now: 1, peak: 1 }))
    const update = vi.spyOn(environmentsApi, 'update').mockReturnValue(ok({ data: { name: 'staging' } }))
    const env = { id: 'e1', name: 'staging', is_primary: false, warm_scale: null,
      overrides: { min_scale: 0, queue_stream: 'orders-st', deploy_strategy: { strategy: 'canary' } } }
    const w = mount(EnvironmentFormModal, { props: { projectId: 'p1', environment: env, project }, global: withPlugins().global })

    const mode = w.find('[data-testid="env-warmer-mode"]')
    expect(mode.element.value).toBe('inherit')
    expect(mode.findAll('option')[0].text()).toBe('The project\'s schedule (1 row)')
    await w.find('form').trigger('submit')
    await flush()
    expect(update.mock.calls[0][1]).toBe('staging')
    expect(update.mock.calls[0][2].overrides).toEqual({ queue_stream: 'orders-st', deploy_strategy: { strategy: 'canary' }, min_scale: 0, env_vars: {} })

    await mode.setValue('own')
    expect(w.findComponent(WarmerSchedule).props('rows')).toEqual([{ cron: '* * * * *', min_scale: 1 }])
    w.findComponent(WarmerSchedule).vm.$emit('update:rows', [{ cron: '0 9 * * 1-5', min_scale: 2 }, { cron: '0 18 * * 1-5', min_scale: 0 }])
    await w.find('form').trigger('submit')
    await flush()
    expect(update.mock.calls[1][2].overrides.scale_schedule).toEqual([{ cron: '0 9 * * 1-5', min_scale: 2 }, { cron: '0 18 * * 1-5', min_scale: 0 }])

    // a count above max_scale cannot be saved
    w.findComponent(WarmerSchedule).vm.$emit('update:rows', [{ cron: '* * * * *', min_scale: 9 }])
    await w.vm.$nextTick()
    expect(w.find('[data-testid="env-save"]').attributes('disabled')).toBeDefined()

    await mode.setValue('off')
    expect(w.findComponent(WarmerSchedule).exists()).toBe(false)
    await w.find('form').trigger('submit')
    await flush()
    expect(update.mock.calls[2][2].overrides.scale_schedule).toEqual([])
  })

  it('the cards name each environment\'s warmer and what it keeps warm now', async () => {
    const list = environments().map(e => ({ ...e, deploy_strategy: 'direct',
      scale_schedule: e.name === 'dev' ? null : [{ cron: '* * * * *', min_scale: 2 }],
      scale_schedule_inherited: e.name !== 'staging' || e.is_primary,
      warm_scale: e.name === 'staging' ? { applied: 2, applied_at: '2026-10-22T10:00:00Z' } : null }))
    vi.spyOn(environmentsApi, 'list').mockReturnValue(ok({ data: list, abilities: { manage: true, promote: true, approve: false } }))
    vi.spyOn(environmentsApi, 'promotions').mockReturnValue(ok({ data: [] }))
    const w = mount(ProjectEnvironments, { props: { project }, global: withPlugins().global })
    await flush()

    expect(w.find('[data-testid="environment-warmer-dev"]').text()).toBe('Timed warmer: off')
    expect(w.find('[data-testid="environment-warmer-staging"]').text()).toBe('Timed warmer: its own schedule · 2 warm now')
    expect(w.find('[data-testid="environment-warmer-production"]').text()).toBe('Timed warmer: the project\'s schedule')

    await w.find('[data-testid="environment-edit-staging"]').trigger('click')
    expect(w.findComponent(EnvironmentFormModal).props('project')).toEqual(project)
  })
})

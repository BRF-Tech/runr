import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ImportPlanView from '@/modules/org-transfer/components/ImportPlanView.vue'
import { orgTransferApi } from '@/modules/org-transfer/api'
import { useConfirm } from '@/composables/useConfirm'
import { makeOrgTransferI18n, importTransfer, plan } from './support'

// The dry-run plan of an import (docs/org-export.md#import): regions, renames, issues, manual
// steps, the passphrase of locked secrets; "check again" sends the choices; Import is refused
// while the plan blocks and resumes a failed import.

vi.mock('@/modules/org-transfer/api', async (importOriginal) => {
  const actual = await importOriginal()
  return { ...actual, orgTransferApi: { plan: vi.fn(), apply: vi.fn() } }
})
vi.mock('@/composables/useConfirm', () => {
  const confirm = vi.fn()
  return { useConfirm: () => ({ confirm }) }
})

const i18n = makeOrgTransferI18n()
const mounted = []

function mountPlan(transfer = importTransfer()) {
  setActivePinia(createPinia())
  const w = mount(ImportPlanView, { props: { transfer, regions: [] }, global: { plugins: [i18n] } })
  mounted.push(w)
  return w
}

const unblocked = () => plan({
  blocking: 0, can_apply: true,
  regions: [{ source_id: 'src-r1', source_name: 'eu-1', target_id: 'r1', target_name: 'eu-west', options: [{ id: 'r1', name: 'eu-west' }, { id: 'r2', name: 'tr-1' }] }],
  projects: plan().projects.map(p => ({ ...p, target_region_id: 'r1', target_region: 'eu-west', issues: p.issues.filter(i => i.level !== 'error') })),
})

beforeEach(() => {
  vi.clearAllMocks()
  useConfirm().confirm.mockResolvedValue(true)
})
afterEach(() => mounted.splice(0).forEach(w => w.unmount()))

describe('ImportPlanView', () => {
  it('shows the source, the projects with their issues, the bindings and the manual steps', () => {
    const w = mountPlan()

    expect(w.find('[data-testid="plan-source"]').text()).toContain('From Acme (acme) — RunR v0.5.10, schema 1')
    const api = w.find('[data-plan-project="p1"]')
    expect(api.find('[data-testid="plan-target-name"]').element.value).toBe('api-2')
    expect(api.find('[data-level="error"]').text()).toContain('Map region eu-1')
    expect(api.find('[data-level="manual"]').text()).toContain('api.example.com needs DNS')
    expect(w.find('[data-plan-binding="b1"]').text()).toContain('with data (1.0 MiB)')
    expect(w.find('[data-testid="plan-manual-steps"]').text()).toContain('api.example.com')
    expect(w.find('[data-testid="plan-organization"]').text()).toContain('a@example.com')
    expect(w.find('[data-testid="plan-secrets"]').attributes('data-state')).toBe('locked')
  })

  it('refuses to import while the plan has blocking issues', async () => {
    const w = mountPlan()
    expect(w.find('[data-testid="plan-blocking"]').text()).toContain('1 blocking issue')
    expect(w.find('[data-testid="import-apply"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="import-apply"]').trigger('click')
    expect(orgTransferApi.apply).not.toHaveBeenCalled()
  })

  it('checks again with the region mapping, renames, skips and the passphrase', async () => {
    orgTransferApi.plan.mockResolvedValue({ data: { data: importTransfer({ plan: unblocked() }) } })
    const w = mountPlan()

    await w.find('[data-region="src-r1"] select').setValue('r2')
    await w.find('[data-plan-project="p1"] [data-testid="plan-target-name"]').setValue('api-copy')
    await w.find('[data-plan-project="p2"] [data-testid="plan-skip"]').setValue(true)
    await w.find('[data-testid="import-passphrase"]').setValue('correct horse battery')
    await w.find('[data-testid="plan-recheck"]').trigger('click')
    await flushPromises()

    expect(orgTransferApi.plan).toHaveBeenCalledWith('im-1', {
      region_map: { 'src-r1': 'r2' },
      renames: { p1: 'api-copy' },
      binding_renames: {},
      skip_projects: ['p2'],
      restore_data: true,
      skip_binding_data: [],
      passphrase: 'correct horse battery',
    })
    expect(w.emitted('changed')[0][0].plan.blocking).toBe(0)
  })

  it('applies an unblocked plan with the options after confirming', async () => {
    orgTransferApi.apply.mockResolvedValue({ data: { data: importTransfer({ status: 'applying', plan: unblocked() }) } })
    const w = mountPlan(importTransfer({ plan: unblocked() }))

    // binding data is in the package: restoring it is offered and on by default
    expect(w.find('[data-testid="import-restore-data"]').element.checked).toBe(true)
    expect(w.find('[data-testid="import-images"]').exists()).toBe(false)
    await w.find('[data-testid="import-deploy"]').setValue(true)
    await w.find('[data-testid="import-apply"]').trigger('click')
    await flushPromises()

    expect(useConfirm().confirm).toHaveBeenCalled()
    expect(orgTransferApi.apply).toHaveBeenCalledWith('im-1', {
      region_map: { 'src-r1': 'r1' },
      renames: { p1: 'api-2' },
      binding_renames: {},
      skip_projects: [],
      restore_data: true,
      skip_binding_data: [],
      images: false,
      invite_members: false,
      deploy: true,
    })
    expect(w.emitted('changed')[0][0].status).toBe('applying')
  })

  it('leaves one binding\'s data out, shows the Redis versions and the run history with its choice', async () => {
    const withHistory = unblocked()
    withHistory.bindings = [{ source_id: 'b1', name: 'cache', type: 'kv', plan: 'shared', target_name: 'cache', data: true, data_bytes: 10, data_restore: true, data_redis_version: '7.4.1', target_redis_version: '7.0.15', status: 'create', issues: [{ level: 'error', code: 'kv_version_older', message: 'older Redis' }] }]
    withHistory.issues = [{ level: 'warning', code: 'billing_off', message: 'Billing is off here' }]
    withHistory.history = { import: true, projects: [{ source_id: 'p1', name: 'api', deployments: 3, workflow_runs: 2, trigger_events: 4, dropped_by_retention: { workflow_runs: 1, trigger_events: 0 } }], usage_records: 5, bills: 2 }
    orgTransferApi.plan.mockResolvedValue({ data: { data: importTransfer({ plan: withHistory }) } })
    const w = mountPlan(importTransfer({ plan: withHistory }))

    expect(w.find('[data-testid="plan-redis-version"]').text()).toContain('7.4.1')
    expect(w.find('[data-testid="plan-redis-version"]').text()).toContain('7.0.15')
    expect(w.find('[data-testid="plan-history"]').text()).toContain('3')
    expect(w.find('[data-testid="plan-history"]').text()).toContain('1')
    expect(w.find('[data-testid="plan-general-issues"] [data-code="billing_off"]').exists()).toBe(true)

    await w.find('[data-plan-binding="b1"] [data-testid="plan-skip-data"]').setValue(true)
    await w.find('[data-testid="import-history"]').setValue(false)
    await w.find('[data-testid="plan-recheck"]').trigger('click')
    await flushPromises()

    const choices = orgTransferApi.plan.mock.calls[0][1]
    expect(choices.skip_binding_data).toEqual(['b1'])
    expect(choices.history).toBe(false)
  })

  it('takes the fresh plan of a refused apply (plan_blocked)', async () => {
    const blocked = importTransfer({ plan: plan({ blocking: 2 }) })
    orgTransferApi.apply.mockRejectedValue({ response: { status: 422, data: { message: 'The plan has blocking issues.', code: 'plan_blocked', data: blocked } } })
    const w = mountPlan(importTransfer({ plan: unblocked() }))

    await w.find('[data-testid="import-apply"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="plan-error"]').text()).toBe('The plan has blocking issues.')
    expect(w.emitted('changed')[0][0].plan.blocking).toBe(2)
  })

  it('offers to resume a failed import and shows the counts of a finished one', async () => {
    const w = mountPlan(importTransfer({ status: 'failed', message: 'Restoring db failed', plan: unblocked() }))
    expect(w.find('[data-testid="import-apply"]').text()).toContain('Resume the import')
    expect(w.find('[data-testid="import-failed-message"]').text()).toBe('Restoring db failed')

    await w.setProps({ transfer: importTransfer({ status: 'completed', plan: unblocked(), report: { counts: { created: 9, exists: 1, skipped: 1, failed: 0, manual: 2 }, items: [], manual_steps: [], warnings: [] } }) })
    expect(w.find('[data-testid="import-apply"]').exists()).toBe(false)
    expect(w.find('[data-testid="import-counts"]').text()).toBe('9 created · 1 existed · 1 skipped · 0 failed · 2 manual')
  })
})

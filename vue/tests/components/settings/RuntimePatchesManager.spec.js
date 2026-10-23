import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { runtimePatchesApi } from '@/api/runtimePatches'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import RuntimePatchesManager from '@/components/settings/RuntimePatchesManager.vue'
import { withPlugins } from '../../helpers'

const find = (w, id) => w.find(`[data-test="${id}"]`)
const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setTimeout(r, 0)) }
const ok = (data) => Promise.resolve({ data })
const fail = (status, data) => Promise.reject(Object.assign(new Error('x'), { response: { status, data } }))

const ZERO = {
  id: 'php-8.3-8.3.36-r2', runtime: 'php', version: '8.3', tag: '8.3.36-r2', level: 'zero-day', reason: 'Remote code execution.',
  reasons: { en: 'Remote code execution.' }, cves: ['CVE-2026-1111'], url: null, published_at: '2026-10-20T08:00:00Z',
  deadline_at: '2026-10-23T08:00:00Z', deadline_extended: false, in_feed: true, overdue: false, affected: 3,
  organizations: [
    { tenant_id: 't-acme', name: 'Acme', affected: 2, migrations: { failed: 1 } },
    { tenant_id: 't-globex', name: 'Globex', affected: 1, migrations: {} },
  ],
}
const ROUTINE = { ...ZERO, id: 'python-3.12-3.12.9-r1', runtime: 'python', version: '3.12', tag: '3.12.9-r1', level: 'routine', deadline_at: null, affected: 0, organizations: [] }

const feed = (over = {}) => ({
  reference: 'ghcr.io/brf-tech/runr/runtime-patches:feed', enabled: true, status: 'ok', error: null, manifest_digest: 'sha256:' + 'c'.repeat(64),
  signature_verified: true, generated_at: '2026-10-21T06:00:00Z', checked_at: '2026-10-21T08:00:00Z', fetched_at: '2026-10-21T06:05:00Z',
  via_region: null, auto_migrate: true, ...over,
})
const payload = (over = {}) => ({ data: {
  feed: feed(), key_configured: true, running_version: '0.5.10', patches: [ZERO, ROUTINE],
  advisories: [{ version: '0.5.11', level: 'critical', reason: 'Fixes a sign-in bypass.', url: null, published_at: null, newer: true }],
  ...over,
} })

async function mountManager(data = payload()) {
  vi.spyOn(runtimePatchesApi, 'admin').mockReturnValue(ok(data))
  const w = mount(RuntimePatchesManager, { global: withPlugins().global })
  await flush()
  return w
}

describe('RuntimePatchesManager (Build & Run → Patches)', () => {
  afterEach(() => vi.restoreAllMocks())

  it('shows the feed, the patches with the projects behind them per organization and the releases', async () => {
    const w = await mountManager()
    expect(find(w, 'runtime-patches-feed-status').text()).toBe('OK')
    expect(find(w, `runtime-patches-affected-${ZERO.id}`).text()).toBe('3')
    expect(find(w, `runtime-patches-extend-${ZERO.id}`).exists()).toBe(true)
    // A routine patch has no deadline to extend
    expect(find(w, `runtime-patches-extend-${ROUTINE.id}`).exists()).toBe(false)

    await find(w, `runtime-patches-row-${ZERO.id}`).find('button').trigger('click')
    const orgs = find(w, `runtime-patches-orgs-${ZERO.id}`).text()
    expect(orgs).toContain('Acme')
    expect(orgs).toContain('Failed: 1')
    expect(find(w, 'runtime-patches-advisories').text()).toContain('RunR 0.5.11')
    expect(find(w, 'runtime-patches-advisories').text()).toContain('newer than the running 0.5.10')
  })

  it('without a public key it says the feed is off', async () => {
    const w = await mountManager(payload({ key_configured: false, feed: feed({ enabled: false, status: 'disabled' }) }))
    expect(find(w, 'runtime-patches-key-missing').exists()).toBe(true)
    expect(find(w, 'runtime-patches-feed-status').text()).toBe('Off')
  })

  it('checks the feed now; a refused feed shows why', async () => {
    useToast().toasts.value = []
    const w = await mountManager()
    const check = vi.spyOn(runtimePatchesApi, 'check').mockImplementation(() => fail(502, { data: feed({ status: 'invalid_signature', error: 'No signature verifies' }) }))
    await find(w, 'runtime-patches-check').trigger('click')
    await flush()
    expect(check).toHaveBeenCalled()
    expect(find(w, 'runtime-patches-feed-status').text()).toBe('Invalid signature')
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'error', message: 'The feed check failed: No signature verifies' })
  })

  it('switches the automatic migration off', async () => {
    const w = await mountManager()
    const policy = vi.spyOn(runtimePatchesApi, 'setPolicy').mockReturnValue(ok({ data: feed({ auto_migrate: false }) }))
    await find(w, 'runtime-patches-policy-toggle').trigger('click')
    await flush()
    expect(policy).toHaveBeenCalledWith(false)
    expect(find(w, 'runtime-patches-policy').text()).toContain('Off')
  })

  it('reminds, forces after a confirmation and extends the deadline', async () => {
    useToast().toasts.value = []
    const w = await mountManager()
    const remind = vi.spyOn(runtimePatchesApi, 'remind').mockReturnValue(ok({ data: { told: 4 } }))
    const force = vi.spyOn(runtimePatchesApi, 'force').mockReturnValue(ok({ data: { started: 3 } }))
    const extend = vi.spyOn(runtimePatchesApi, 'extendDeadline').mockReturnValue(ok({ data: { ...ZERO, deadline_at: '2026-10-30T12:00:00.000Z', deadline_extended: true } }))

    await find(w, `runtime-patches-remind-${ZERO.id}`).trigger('click')
    await flush()
    expect(remind).toHaveBeenCalledWith(ZERO.id)
    expect(useToast().toasts.value.at(-1).message).toBe('4 user(s) notified again.')

    const forcing = w.vm.force(ZERO)
    await flush()
    expect(useConfirm().current.value.destructive).toBe(true)
    useConfirm().accept()
    await forcing
    expect(force).toHaveBeenCalledWith(ZERO.id)

    await find(w, `runtime-patches-extend-${ZERO.id}`).trigger('click')
    await find(w, 'runtime-patches-extend-input').setValue('2026-10-30T12:00')
    await w.vm.saveDeadline()
    await flush()
    expect(extend).toHaveBeenCalledWith(ZERO.id, new Date('2026-10-30T12:00').toISOString())
    expect(find(w, `runtime-patches-deadline-${ZERO.id}`).text()).toContain('extended')
    expect(find(w, 'runtime-patches-extend-modal').exists()).toBe(false)
  })

  it('a force that is cancelled does nothing', async () => {
    const w = await mountManager()
    const force = vi.spyOn(runtimePatchesApi, 'force')
    const forcing = w.vm.force(ZERO)
    await flush()
    useConfirm().cancel()
    await forcing
    expect(force).not.toHaveBeenCalled()
  })
})

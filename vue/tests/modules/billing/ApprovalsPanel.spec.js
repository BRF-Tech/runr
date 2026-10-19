import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import client from '@/api/client'
import { useConfirm } from '@/composables/useConfirm'
import ApprovalsPanel from '@/modules/billing/components/ApprovalsPanel.vue'
import { fail, fixedRequest, flush, globalOptions, grant, ok } from './helpers'

vi.mock('@/api/client', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

const find = (w, id) => w.find(`[data-test="${id}"]`)

async function mountPanel(permissions = ['billing.view', 'billing.approve']) {
  const options = globalOptions()
  grant(permissions)
  const w = mount(ApprovalsPanel, { global: options })
  await flush()
  return w
}

describe('ApprovalsPanel', () => {
  beforeEach(() => {
    vi.mocked(client.get).mockReset().mockReturnValue(ok({ data: [fixedRequest(), fixedRequest({ id: 'fr-2', gpu_count: 1 })], meta: { total: 2 } }))
    vi.mocked(client.post).mockReset()
  })

  it('lists the pending requests with resources and monthly price', async () => {
    const w = await mountPanel()
    expect(client.get).toHaveBeenCalledWith('/billing/fixed-requests', { params: { per_page: 100, status: 'pending' } })
    expect(find(w, 'request-resources-fr-1').text()).toContain('2 vCPU')
    expect(find(w, 'request-resources-fr-1').text()).toContain('4 GB')
    expect(find(w, 'request-resources-fr-2').text()).toContain('1 GPU')
    expect(find(w, 'request-fr-1').text()).toContain('$62.00 / month')
    expect(find(w, 'request-fr-1').text()).toContain('Requested by Bob')
  })

  it('approves after confirmation and drops the request from the pending list', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ data: fixedRequest({ status: 'approved', active_from: '2026-10-19' }) }))
    const w = await mountPanel()
    const done = w.vm.approve(fixedRequest())
    await flush(1)
    useConfirm().accept()
    await done
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/fixed-requests/fr-1/approve', {})
    expect(find(w, 'request-fr-1').exists()).toBe(false)

    // a cancelled confirmation sends nothing
    vi.mocked(client.post).mockClear()
    const cancelled = w.vm.approve(fixedRequest({ id: 'fr-2' }))
    await flush(1)
    useConfirm().cancel()
    await cancelled
    expect(client.post).not.toHaveBeenCalled()
  })

  it('rejects with a required reason', async () => {
    vi.mocked(client.post).mockReturnValue(ok({ data: fixedRequest({ id: 'fr-2', status: 'rejected', decision_reason: 'Full' }) }))
    const w = await mountPanel()
    await find(w, 'request-reject-fr-2').trigger('click')
    await find(w, 'request-reject-confirm-fr-2').trigger('click')
    expect(client.post).not.toHaveBeenCalled()

    await find(w, 'request-reject-reason-fr-2').setValue('Full')
    await find(w, 'request-reject-confirm-fr-2').trigger('click')
    await flush()
    expect(client.post).toHaveBeenCalledWith('/billing/fixed-requests/fr-2/reject', { reason: 'Full' })
    expect(find(w, 'request-fr-2').exists()).toBe(false)
  })

  it('shows decisions in the full list; without billing.approve there are no actions', async () => {
    vi.mocked(client.get).mockReturnValue(ok({ data: [fixedRequest({ status: 'rejected', decided_by: { id: 'u1', name: 'Ada' }, decided_at: '2026-10-19T10:00:00Z', decision_reason: 'Full' })] }))
    const w = await mountPanel(['billing.view'])
    await find(w, 'approvals-filter-status').setValue('')
    await flush()
    expect(client.get).toHaveBeenLastCalledWith('/billing/fixed-requests', { params: { per_page: 100 } })
    expect(w.text()).toContain('Decided by Ada')
    expect(w.text()).toContain('Reason: Full')
    expect(find(w, 'request-approve-fr-1').exists()).toBe(false)
  })

  it('shows an empty list and load errors', async () => {
    vi.mocked(client.get).mockReturnValue(ok({ data: [] }))
    const w = await mountPanel()
    expect(find(w, 'approvals-empty').exists()).toBe(true)
    vi.mocked(client.get).mockImplementation(() => fail(500, { message: 'Down.' }))
    await w.vm.load()
    await flush()
    expect(w.text()).toContain('Down.')
  })
})

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { deferred } from '../helpers'

vi.mock('@/api/ai', () => ({ aiApi: { services: vi.fn(), models: vi.fn() } }))

import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'

const page = (rows) => ({ data: { data: rows, abilities: { view: true, deploy: true, manage: true } } })

// The models page's poll and the actions' answers (deferred promises): the store keeps the
// action's state over a poll answer read before it, and the newest poll over an older one
describe('models store: answers arriving late', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('a stop is not undone by a poll that was read before it', async () => {
    const ai = useAiStore()
    aiApi.services.mockResolvedValueOnce(page([{ id: 'ms1', status: 'ready' }]))
    await ai.fetchServices()

    const poll = deferred()
    aiApi.services.mockReturnValueOnce(poll.promise)
    const polling = ai.fetchServices()
    ai.upsertService({ id: 'ms1', status: 'stopping' })   // the stop's answer
    poll.resolve(page([{ id: 'ms1', status: 'ready' }]))
    await polling

    expect(ai.services.find(s => s.id === 'ms1').status).toBe('stopping')
  })

  it('a poll answer newer than the action (updated_at) wins', async () => {
    const ai = useAiStore()
    const poll = deferred()
    aiApi.services.mockReturnValueOnce(poll.promise)
    const polling = ai.fetchServices()
    ai.upsertService({ id: 'ms1', status: 'stopping', updated_at: '2026-10-21T10:00:00Z' })
    poll.resolve(page([{ id: 'ms1', status: 'stopped', updated_at: '2026-10-21T10:00:05Z' }]))
    await polling

    expect(ai.services.find(s => s.id === 'ms1').status).toBe('stopped')
  })

  it('a deleted service stays deleted when an older poll lands', async () => {
    const ai = useAiStore()
    const poll = deferred()
    aiApi.services.mockReturnValueOnce(poll.promise)
    const polling = ai.fetchServices()
    ai.removeService('ms1')
    poll.resolve(page([{ id: 'ms1', status: 'ready' }, { id: 'ms2', status: 'ready' }]))
    await polling

    expect(ai.services.map(s => s.id)).toEqual(['ms2'])
  })

  it('keeps the newer of two polls', async () => {
    const ai = useAiStore()
    const older = deferred()
    aiApi.models.mockReturnValueOnce(older.promise).mockResolvedValueOnce(page([{ id: 'am1', snapshots: [{ id: 'sn1', status: 'ready' }] }]))
    const first = ai.fetchModels()
    await ai.fetchModels()
    older.resolve(page([{ id: 'am1', snapshots: [{ id: 'sn1', status: 'pending' }] }]))
    await first

    expect(ai.models[0].snapshots[0].status).toBe('ready')
  })

  it('puts a new offline copy on the model as the list has it now', async () => {
    const ai = useAiStore()
    aiApi.models.mockResolvedValueOnce(page([{ id: 'am1', snapshots: [{ id: 'sn1', status: 'failed' }] }]))
    await ai.fetchModels()

    ai.upsertSnapshot('am1', { id: 'sn2', status: 'pending' })
    expect(ai.models[0].snapshots.map(s => [s.id, s.status])).toEqual([['sn1', 'failed'], ['sn2', 'pending']])

    ai.removeSnapshot('am1', 'sn1')
    expect(ai.models[0].snapshots.map(s => s.id)).toEqual(['sn2'])
  })
})

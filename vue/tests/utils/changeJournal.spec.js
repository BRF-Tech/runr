import { describe, it, expect } from 'vitest'
import { createChangeJournal, insertChange, removeChange, updateChange } from '@/utils/changeJournal'

// Changes made to a list while a reload of it is in flight, applied to the reload's answer
describe('changeJournal', () => {
  it('replays only the changes recorded after the request started, in order', () => {
    const journal = createChangeJournal()
    journal.record(insertChange({ id: 'before' }))
    const since = journal.mark()
    journal.record(insertChange({ id: 'a', status: 'pending' }))
    journal.record(updateChange('a', { status: 'active' }))
    journal.record(removeChange('gone'))

    const answer = [{ id: 'gone' }, { id: 'kept' }]
    expect(journal.replay(answer, since)).toEqual([{ id: 'a', status: 'active' }, { id: 'kept' }])
  })

  it('changes are idempotent on an answer that already has them', () => {
    const journal = createChangeJournal()
    const since = journal.mark()
    journal.record(insertChange({ id: 'a', status: 'pending' }))
    journal.record(updateChange('a', { status: 'active' }))

    expect(journal.replay([{ id: 'a', status: 'active' }], since)).toEqual([{ id: 'a', status: 'active' }])
  })

  it('updates in place, by id or by a predicate', () => {
    const item = { id: 'v1', number: 3, build_status: 'building' }
    const list = [item]
    expect(updateChange(v => v.number === 3, { build_status: 'ready' })(list)).toBe(list)
    expect(item.build_status).toBe('ready')
  })

  it('keeps a bounded number of changes', () => {
    const journal = createChangeJournal(2)
    const since = journal.mark()
    for (const id of ['a', 'b', 'c']) journal.record(insertChange({ id }))
    expect(journal.replay([], since).map(x => x.id)).toEqual(['c', 'b'])
  })
})

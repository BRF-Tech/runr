/**
 * Changes to a loaded list that a reload of the list can overtake: a websocket event or an
 * action's answer that arrives while a GET of the list is in flight. That GET may have been read
 * before the change, and its answer would drop it (a new item disappears, a status goes back).
 *
 *   const journal = createChangeJournal()
 *
 *   // every change made to the list in place is recorded as a function list → list
 *   function onStatus(id, status) {
 *     const apply = (list) => list.map(d => (d.id === id ? { ...d, status } : d))
 *     items.value = apply(items.value)
 *     journal.record(apply)
 *   }
 *
 *   // a reload applies the changes made after it started on top of its answer
 *   async function load() {
 *     const since = journal.mark()
 *     const { data } = await api.list()
 *     items.value = journal.replay(data, since)
 *   }
 *
 * A change is replayed onto an answer that may already hold it: the change functions are
 * idempotent (an insert skips an id the list has, an update sets values, a removal filters).
 */
export function createChangeJournal(limit = 200) {
  let seq = 0
  let entries = []

  return {
    /** The position a request starts at. */
    mark() {
      return seq
    },
    /** Records a change (list → list) applied to the list. */
    record(apply) {
      entries.push({ seq: ++seq, apply })
      if (entries.length > limit) entries = entries.slice(-limit)
    },
    /** `list` (the answer of a request started at `since`) with the changes made after it. */
    replay(list, since) {
      return entries.reduce((acc, e) => (e.seq > since ? e.apply(acc) : acc), list)
    },
  }
}

/** Change: inserts `item` at the top unless the list has its id. */
export const insertChange = (item) => (list) => (list.some(x => x.id === item.id) ? list : [item, ...list])

/**
 * Change: sets `fields` on the items `match` picks (an id, or a predicate). In place, so views that
 * hold the item (an open deployment's log panel, the selected version) see it as well.
 */
export const updateChange = (match, fields) => (list) => {
  const picks = typeof match === 'function' ? match : (x) => x.id === match
  for (const x of list) if (picks(x)) Object.assign(x, fields)
  return list
}

/** Change: removes the item with `id`. */
export const removeChange = (id) => (list) => list.filter(x => x.id !== id)

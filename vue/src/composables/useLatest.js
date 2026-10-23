import { getCurrentScope, onScopeDispose } from 'vue'

/**
 * Last-call-wins guard for async work whose answer may arrive late: a loader that runs again
 * when a route parameter, a prop or a filter changes, a refresh that a websocket event or a
 * user action can overtake, a flow that navigates when it ends.
 *
 *   const latest = useLatest()
 *
 *   async function load() {
 *     const current = latest.begin()     // supersedes every call still in flight
 *     try {
 *       const { data } = await api.list(filter.value)
 *       if (!current()) return            // a newer call started, or the component is gone
 *       rows.value = data
 *     } catch (e) {
 *       if (current()) error.value = message(e)
 *     } finally {
 *       if (current()) loading.value = false
 *     }
 *   }
 *
 * begin()     starts a call and returns its check: true while it is the newest call and the
 *             owner (component / effect scope) is still alive
 * cancel()    supersedes the calls in flight without starting one (e.g. local state changed)
 * alive()     false once the owner was unmounted (for work that is not a superseding call)
 */
export function useLatest() {
  let seq = 0
  let disposed = false
  if (getCurrentScope()) onScopeDispose(() => { disposed = true })

  return {
    begin() {
      const id = ++seq
      return () => !disposed && id === seq
    },
    cancel() {
      seq++
    },
    alive() {
      return !disposed
    },
  }
}

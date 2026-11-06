import { afterEach, describe, expect, it, vi } from 'vitest'
import { RELOAD_INTERVAL_MS, RELOAD_KEY, reloadOnStaleChunks } from '@/utils/staleChunks'

// After an upgrade a console opened before it asks for the previous build's chunks, which the
// UI's web server answers with 404 (vue/Caddyfile, ci/check-caddyfiles.sh) instead of index.html
// under the immutable header. Vite dispatches vite:preloadError: the console loads again, once.

function memoryStorage() {
  const data = new Map()
  return { getItem: (k) => (data.has(k) ? data.get(k) : null), setItem: (k, v) => data.set(k, String(v)) }
}

function preloadError() {
  return new Event('vite:preloadError', { cancelable: true })
}

describe('reloadOnStaleChunks', () => {
  const stops = []
  afterEach(() => { stops.splice(0).forEach(stop => stop()) })

  function listen(options) {
    const reload = vi.fn()
    const win = { location: { reload }, addEventListener: window.addEventListener.bind(window), removeEventListener: window.removeEventListener.bind(window) }
    stops.push(reloadOnStaleChunks({ win, ...options }))
    return reload
  }

  it('loads the console again when a chunk fails to load, and handles the error', () => {
    const storage = memoryStorage()
    const reload = listen({ storage, now: () => 1_000_000 })

    const event = preloadError()
    window.dispatchEvent(event)

    expect(reload).toHaveBeenCalledTimes(1)
    expect(event.defaultPrevented).toBe(true)
    expect(storage.getItem(RELOAD_KEY)).toBe('1000000')
  })

  it('reloads at most once a minute: a chunk the new build lacks too ends in the error', () => {
    let clock = 1_000_000
    const storage = memoryStorage()
    const reload = listen({ storage, now: () => clock })

    window.dispatchEvent(preloadError())
    clock += RELOAD_INTERVAL_MS - 1
    const again = preloadError()
    window.dispatchEvent(again)
    expect(reload).toHaveBeenCalledTimes(1)
    expect(again.defaultPrevented).toBe(false)

    clock += 1
    window.dispatchEvent(preloadError())
    expect(reload).toHaveBeenCalledTimes(2)
  })

  it('does not reload without storage (blocked site data): no loop it could not stop', () => {
    const blocked = { getItem: () => { throw new Error('SecurityError') }, setItem: () => { throw new Error('SecurityError') } }
    const reload = listen({ storage: blocked })

    const event = preloadError()
    window.dispatchEvent(event)
    expect(reload).not.toHaveBeenCalled()
    expect(event.defaultPrevented).toBe(false)
  })

  it('stops listening', () => {
    const reload = listen({ storage: memoryStorage() })
    stops.pop()()
    window.dispatchEvent(preloadError())
    expect(reload).not.toHaveBeenCalled()
  })
})

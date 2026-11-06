/**
 * After an upgrade, a console opened before it still names the previous build's chunks when it
 * opens a page it has not loaded yet. The UI's web server answers 404 for them (vue/Caddyfile) and
 * Vite reports the failed import as `vite:preloadError`: the console then loads again, with the
 * new build's index.html (never cached), instead of a page that does not open.
 *
 * At most once a minute (sessionStorage): a chunk the new build lacks too ends in the error, not
 * in a reload loop. Without storage (blocked site data) there is no reload either.
 */
export const RELOAD_KEY = 'runr-stale-chunk-reload'
export const RELOAD_INTERVAL_MS = 60_000

/** Listens for failed chunk loads; returns the function that stops listening. */
export function reloadOnStaleChunks({
  win = globalThis.window,
  storage = globalThis.sessionStorage,
  now = () => Date.now(),
} = {}) {
  const onPreloadError = (event) => {
    try {
      const last = Number(storage.getItem(RELOAD_KEY)) || 0
      if (now() - last < RELOAD_INTERVAL_MS) return
      storage.setItem(RELOAD_KEY, String(now()))
    } catch {
      return
    }
    // handled: Vite does not throw the error into the navigation that reloads anyway
    event.preventDefault()
    win.location.reload()
  }
  win.addEventListener('vite:preloadError', onPreloadError)
  return () => win.removeEventListener('vite:preloadError', onPreloadError)
}

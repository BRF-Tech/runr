import type { Page, Route } from '@playwright/test'
import { compile, parseBody, type Gate, type MockApi, type MockRequest } from './mock-api'

// Late and out-of-order API answers, in both modes: a spec holds an answer back and puts a
// websocket event, a second click, a filter change or a navigation between the request and
// its answer — the orderings a slow API or a busy network produce, which a fast local run never
// shows.
//
//   const saved = await network.hold('PUT', '/projects/:id/mcp-server')
//   await page.getByRole('button', { name: 'Create' }).click()
//   const req = await saved.arrived      // the API has handled the request (req.params.id …)
//   ...                                  // the page has no answer yet: assert the state in between
//   saved.release()                      // the answer reaches the page now
//
// mock  → MockApi.hold(): the fake handles the request at once, its answer waits for release()
// stack → page.route(): the real API handles the request (route.fetch()), the browser gets the
//         answer at release(); broadcasts the API sends meanwhile (project.created …) arrive
//         over the real websocket before it
// Paths are API paths without the /api prefix, with :params like MockApi.on().

export class Network {
  private readonly releases: Array<() => void> = []

  constructor(private readonly page: Page, private readonly mockApi: MockApi | null) {}

  /** Holds the answers to the next `times` requests matching method + path until release(). */
  async hold(method: string, path: string | RegExp, { times = 1 }: { times?: number } = {}): Promise<Gate> {
    if (this.mockApi) {
      const gate = this.mockApi.hold(method, path, { times })
      this.releases.push(gate.release)
      return gate
    }

    const verb = method.toUpperCase()
    const { pattern, keys } = compile(path)
    let remaining = times
    let open!: () => void
    let arrive!: (r: MockRequest) => void
    const released = new Promise<void>(resolve => { open = resolve })
    const arrived = new Promise<MockRequest>(resolve => { arrive = resolve })
    const requests: MockRequest[] = []
    const apiPath = (url: URL) => url.pathname.replace(/^\/api/, '') || '/'

    await this.page.route(url => url.pathname.startsWith('/api/') && pattern.test(apiPath(url)), async (route: Route) => {
      const request = route.request()
      if (remaining <= 0 || request.method() !== verb) return route.fallback()
      remaining -= 1
      const url = new URL(request.url())
      const m = pattern.exec(apiPath(url))
      const req: MockRequest = {
        method: verb,
        path: apiPath(url),
        params: Object.fromEntries(keys.map((k, i) => [k, decodeURIComponent(m?.[i + 1] ?? '')])),
        query: url.searchParams,
        body: parseBody(request),
        headers: request.headers(),
      }
      let response
      try {
        response = await route.fetch()
      } catch {
        return route.abort()
      }
      requests.push(req)
      arrive(req)
      await released
      await route.fulfill({ response }).catch(() => { /* the page is gone */ })
    })

    const release = () => { remaining = 0; open() }
    this.releases.push(release)
    return { arrived, requests, release }
  }

  /** Lets every held answer go out (the fixture does it after each test). */
  releaseAll(): void {
    for (const release of this.releases) release()
  }
}

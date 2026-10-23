import type { Page } from '@playwright/test'

/**
 * Lets the page finish what already reached it (an answer released by network.hold(), a
 * websocket message): two animation frames and a task. For assertions that something did NOT
 * happen after a late answer — expect() retries until a check passes, so without this a
 * negative check could pass before the late answer was processed.
 */
export async function settle(page: Page): Promise<void> {
  await page.evaluate(() => new Promise<void>(resolve => {
    requestAnimationFrame(() => requestAnimationFrame(() => setTimeout(resolve, 0)))
  }))
}

export interface Broadcasts {
  /** Resolves once a websocket message containing `text` has reached the page. */
  waitFor(text: string, timeoutMs?: number): Promise<void>
}

/**
 * Records the websocket messages the page receives (stack mode: the API's broadcasts over
 * Reverb), so a spec knows a broadcast has arrived before it releases a held answer. Call it
 * before the page opens its socket (before page.goto).
 */
export function recordBroadcasts(page: Page): Broadcasts {
  const frames: string[] = []
  const listeners = new Set<() => void>()
  page.on('websocket', (ws) => {
    ws.on('framereceived', (frame) => {
      frames.push(typeof frame.payload === 'string' ? frame.payload : frame.payload.toString())
      listeners.forEach(fn => fn())
    })
  })
  return {
    waitFor(text, timeoutMs = 15_000) {
      return new Promise<void>((resolve, reject) => {
        const seen = () => frames.some(f => f.includes(text))
        if (seen()) {
          resolve()
          return
        }
        const timer = setTimeout(() => {
          listeners.delete(onFrame)
          reject(new Error(`no websocket message containing "${text}" within ${timeoutMs} ms`))
        }, timeoutMs)
        const onFrame = () => {
          if (!seen()) return
          clearTimeout(timer)
          listeners.delete(onFrame)
          resolve()
        }
        listeners.add(onFrame)
      })
    },
  }
}

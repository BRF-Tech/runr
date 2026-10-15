/**
 * Event-driven job completion handler.
 *
 * Allows components to register handlers for PlatformEvent topics
 * dispatched by the backend when queue jobs complete or fail.
 *
 * Usage:
 *   useJobEvents({
 *     'knative.operator.installed': (payload) => { ... },
 *     'sandbox.*': (payload, topic) => { ... },  // wildcard
 *   })
 */
import { onUnmounted } from 'vue'

// Global registry: topic → Set<callback>
const handlers = new Map()

/**
 * Dispatch a platform event to all registered handlers.
 * Called from useRealtimeNotifications when a platform event arrives.
 */
export function dispatchPlatformEvent(topic, payload) {
  // Exact match
  const exact = handlers.get(topic)
  if (exact) exact.forEach(cb => cb(payload, topic))

  // Wildcard: 'knative.*' matches 'knative.operator.installed'
  for (const [pattern, cbs] of handlers) {
    if (pattern.endsWith('.*') && topic.startsWith(pattern.slice(0, -1))) {
      cbs.forEach(cb => cb(payload, topic))
    }
  }
}

/**
 * Register event handlers scoped to the component lifecycle.
 * Handlers are automatically cleaned up on unmount.
 *
 * @param {Object} topicMap - { topic: handler } pairs
 */
export function useJobEvents(topicMap) {
  const entries = Object.entries(topicMap)

  for (const [topic, handler] of entries) {
    if (!handlers.has(topic)) handlers.set(topic, new Set())
    handlers.get(topic).add(handler)
  }

  onUnmounted(() => {
    for (const [topic, handler] of entries) {
      const set = handlers.get(topic)
      if (set) {
        set.delete(handler)
        if (set.size === 0) handlers.delete(topic)
      }
    }
  })
}

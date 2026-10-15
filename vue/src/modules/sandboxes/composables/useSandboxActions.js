import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { sandboxesApi } from '../api/sandboxes'
import { apiError, formatDuration, secondsUntil, shortId } from '../utils'

/**
 * Lifecycle actions shared by the list and the detail panel: kill (confirmed), extend the
 * lifetime, pause / resume. Each returns the updated sandbox, or null when nothing changed.
 */
export function useSandboxActions() {
  const { t } = useI18n()
  const { confirm } = useConfirm()
  const toast = useToast()

  function failed(e) {
    toast.error(apiError(e, t('sandboxes.list.actionFailed')))
    return null
  }

  async function kill(sandbox) {
    const ok = await confirm({
      title:        t('sandboxes.list.killTitle'),
      message:      t('sandboxes.list.killConfirm', { id: shortId(sandbox.id) }),
      destructive:  true,
      confirmLabel: t('sandboxes.actions.kill'),
    })
    if (!ok) return null
    try {
      const { data } = await sandboxesApi.destroy(sandbox.id)
      toast.success(t('sandboxes.list.killed', { id: shortId(sandbox.id) }))
      return data?.data ?? { ...sandbox, status: 'terminated' }
    } catch (e) {
      return failed(e)
    }
  }

  /** Lifetime = what is left + seconds (the API sets expiry = now + ttl_seconds). */
  async function extend(sandbox, seconds = 900) {
    const left = Math.max(0, Math.round(secondsUntil(sandbox.expires_at) ?? 0))
    const ttl  = left + seconds
    try {
      const { data } = await sandboxesApi.setTimeout(sandbox.id, ttl)
      const updated = data?.data ?? sandbox
      const remaining = secondsUntil(updated.expires_at) ?? ttl
      toast.success(t('sandboxes.list.extended', { id: shortId(sandbox.id), time: formatDuration(remaining) }))
      return updated
    } catch (e) {
      return failed(e)
    }
  }

  async function pause(sandbox) {
    try {
      const { data } = await sandboxesApi.pause(sandbox.id)
      toast.success(t('sandboxes.list.paused', { id: shortId(sandbox.id) }))
      return data?.data ?? { ...sandbox, status: 'paused' }
    } catch (e) {
      return failed(e)
    }
  }

  async function resume(sandbox) {
    try {
      const { data } = await sandboxesApi.resume(sandbox.id)
      toast.success(t('sandboxes.list.resumed', { id: shortId(sandbox.id) }))
      return data?.data ?? { ...sandbox, status: 'running' }
    } catch (e) {
      return failed(e)
    }
  }

  /** startSession for ShellTerminalModal: one-time id of the web-shell WebSocket. */
  function terminalSession(sandbox) {
    return async (cols, rows) => {
      const { data } = await sandboxesApi.terminal(sandbox.id, cols, rows)
      const payload = data?.data ?? data
      return payload.session_id
    }
  }

  function keepalive(sandbox) {
    return () => sandboxesApi.keepalive(sandbox.id)
  }

  return { kill, extend, pause, resume, terminalSession, keepalive }
}

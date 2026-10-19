import { useRoute, useRouter } from 'vue-router'
import { moduleRegistry } from '@/modules/index'
import { clearEtagCache } from '@/api/client'
import { useSettingsStore } from '@/stores/settings'
import { setBroadcastChannelPrefix, resubscribeBroadcast } from '@/composables/useRealtimeNotifications'
import { useTenantStore } from '../stores/tenant'

/**
 * Entering an organization (or the platform, as platform administrator) from anywhere in the
 * console: the tenant store switches (a platform administrator's switch into an organization they
 * are no member of is an impersonation, audit-logged), then everything scoped to the previous
 * context is dropped — broadcast channels, ETag cache, settings, the current view.
 */
export function useTenantSwitch() {
  const store = useTenantStore()
  const settingsStore = useSettingsStore()
  const router = useRouter()
  const route = useRoute()

  function afterSwitch(channelPrefix, { toProjects = false } = {}) {
    setBroadcastChannelPrefix(channelPrefix)
    resubscribeBroadcast()
    clearEtagCache()
    settingsStore.invalidate()
    if (toProjects || route.path.startsWith('/projects/') || route.path.startsWith('/support')) {
      router.push('/projects')
    }
    moduleRegistry.viewKey++
  }

  /** @returns {Promise<boolean>} false when the switch was refused */
  async function enter(tenantId, options = {}) {
    const ok = await store.switchTenant(tenantId)
    if (ok) afterSwitch(`tenant.${tenantId}`, options)
    return ok
  }

  async function leave(options = {}) {
    await store.switchTenant(null)
    afterSwitch('', options)
  }

  return { enter, leave }
}

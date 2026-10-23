import { defineStore } from 'pinia'
import { ref } from 'vue'
import { triggersApi } from '../api/triggers'
import { PLATFORM_EVENTS, WEBHOOK_PRESETS, sortTypes } from '../utils/triggers'

/** GET /trigger-types, loaded once: the types with their metadata, platform events, webhook presets. */
export const useTriggerTypesStore = defineStore('triggerTypes', () => {
  const types          = ref([])
  const platformEvents = ref(PLATFORM_EVENTS)
  const webhookPresets = ref(WEBHOOK_PRESETS)
  const loaded         = ref(false)

  let pending = null

  async function load() {
    if (loaded.value) return
    pending ??= triggersApi.types()
      .then(({ data }) => {
        types.value          = sortTypes(data?.data ?? [])
        platformEvents.value = data?.platform_events?.length ? data.platform_events : PLATFORM_EVENTS
        webhookPresets.value = data?.webhook_presets?.length ? data.webhook_presets : WEBHOOK_PRESETS
        loaded.value         = true
      })
      .finally(() => { pending = null })
    return pending
  }

  return { types, platformEvents, webhookPresets, loaded, load }
})

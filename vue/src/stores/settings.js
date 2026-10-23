import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingsApi } from '@/api/settings'

export const useSettingsStore = defineStore('settings', () => {
  const data    = ref({
    base_domain: '',
    total_cpu_m: 0,
    total_memory_mb: 0,
    regions: [],
    executor_images: [],
    gpu_demo_enabled: false,
    custom_runtimes_enabled: false,
    // The organization's time zone: the timed warmer's crons run in it (default Europe/Istanbul)
    timezone: 'Europe/Istanbul',
  })
  const loaded  = ref(false)
  // invalidate() (an organization switch) drops the fetches in flight: their answers belong to
  // the organization left
  let generation = 0

  // Callers at the same time (the header and the page) share one request
  let pending = null

  function fetch() {
    const gen = generation
    pending ??= settingsApi.get()
      .then(({ data: d }) => {
        if (gen !== generation) return
        data.value = {
          ...d,
          base_domain:      d.base_domain      ?? '',
          total_cpu_m:      d.total_cpu_m      ?? 0,
          total_memory_mb:  d.total_memory_mb  ?? 0,
          regions:          d.regions          ?? [],
          executor_images:  d.executor_images  ?? [],
          gpu_demo_enabled: d.gpu_demo_enabled ?? false,
          custom_runtimes_enabled: d.custom_runtimes_enabled ?? false,
          timezone:         d.timezone         || 'Europe/Istanbul',
        }
        loaded.value = true
      })
      .finally(() => { pending = null })
    return pending
  }

  function update(newData) {
    data.value = {
      ...data.value,
      ...newData,
      base_domain:      newData.base_domain      ?? '',
      total_cpu_m:      newData.total_cpu_m      ?? 0,
      total_memory_mb:  newData.total_memory_mb  ?? 0,
      regions:          newData.regions          ?? data.value.regions,
      executor_images:  newData.executor_images  ?? data.value.executor_images,
      gpu_demo_enabled: newData.gpu_demo_enabled ?? data.value.gpu_demo_enabled,
      custom_runtimes_enabled: newData.custom_runtimes_enabled ?? data.value.custom_runtimes_enabled,
      timezone:         newData.timezone         || data.value.timezone || 'Europe/Istanbul',
    }
  }

  function invalidate() {
    generation++
    // the next fetch asks again: a request in flight answers for the organization left
    pending = null
    loaded.value = false
  }

  return { data, loaded, fetch, update, invalidate }
})

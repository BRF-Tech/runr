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
  })
  const loaded  = ref(false)

  async function fetch() {
    const { data: d } = await settingsApi.get()
    data.value = {
      ...d,
      base_domain:      d.base_domain      ?? '',
      total_cpu_m:      d.total_cpu_m      ?? 0,
      total_memory_mb:  d.total_memory_mb  ?? 0,
      regions:          d.regions          ?? [],
      executor_images:  d.executor_images  ?? [],
      gpu_demo_enabled: d.gpu_demo_enabled ?? false,
      custom_runtimes_enabled: d.custom_runtimes_enabled ?? false,
    }
    loaded.value = true
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
    }
  }

  function invalidate() {
    loaded.value = false
  }

  return { data, loaded, fetch, update, invalidate }
})

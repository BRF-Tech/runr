<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import MonacoEditor from '@/components/MonacoEditor.vue'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import { runtimesApi } from '@/api/runtimes'
import { apiErrorMessage } from '@/composables/useRuntimesManager'

const props = defineProps({ runtime: { type: Object, required: true } })
const emit  = defineEmits(['close'])
const { t } = useI18n()

const loading = ref(true)
const error   = ref(null)
const detail  = ref(null)
const view    = ref('source') // source | normalized

onMounted(async () => {
  try {
    const { data } = await runtimesApi.get(props.runtime.id)
    detail.value = data.data ?? data
    if (!detail.value.manifest_source) view.value = 'normalized'
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
})

const content = computed(() => {
  if (!detail.value) return ''
  if (view.value === 'source' && detail.value.manifest_source) return detail.value.manifest_source
  return JSON.stringify(detail.value.manifest ?? {}, null, 2)
})

const fileNames = computed(() => {
  const f = detail.value?.files
  if (Array.isArray(f)) return f
  return f && typeof f === 'object' ? Object.keys(f) : []
})
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-4xl">
      <div class="card">
        <div class="flex items-center justify-between gap-4 mb-4">
          <div class="flex items-center gap-3 min-w-0">
            <RuntimeIcon :runtime="runtime.id" :size="22" />
            <div class="min-w-0">
              <h2 class="text-base font-semibold text-gray-100 truncate">{{ t('runtimes.manifest.title', { name: runtime.name }) }}</h2>
              <p class="text-[11px] text-gray-400 font-mono">{{ runtime.id }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <div class="flex rounded-md border border-gray-700 overflow-hidden text-xs">
              <button type="button" class="px-2.5 py-1" :class="view === 'source' ? 'bg-brand-600 text-white' : 'text-gray-400 hover:text-gray-100'"
                :disabled="!detail?.manifest_source" @click="view = 'source'">{{ t('runtimes.manifest.source') }}</button>
              <button type="button" class="px-2.5 py-1" :class="view === 'normalized' ? 'bg-brand-600 text-white' : 'text-gray-400 hover:text-gray-100'"
                @click="view = 'normalized'">{{ t('runtimes.manifest.normalized') }}</button>
            </div>
            <button class="text-gray-400 hover:text-gray-100 transition-colors" @click="emit('close')">{{ t('common.close') }}</button>
          </div>
        </div>

        <div v-if="loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
        <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
        <template v-else>
          <div class="rounded-lg border border-gray-800 overflow-hidden">
            <MonacoEditor :model-value="content" :filename="view === 'source' ? 'runtime.yaml' : 'runtime.json'" height="60vh" readonly />
          </div>
          <p v-if="fileNames.length" class="text-xs text-gray-400 mt-3">
            {{ t('runtimes.manifest.files') }}: <span class="font-mono text-gray-300">{{ fileNames.join(', ') }}</span>
          </p>
        </template>
      </div>
    </div>
  </div>
</template>

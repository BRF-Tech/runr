<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDown, FileCode2 } from 'lucide-vue-next'
import MonacoEditor from '@/components/MonacoEditor.vue'
import { runtimesApi } from '@/api/runtimes'

// Starter code the new project gets from the runtime manifest (template.files)
const props = defineProps({
  runtime:     { type: String, default: null },
  serviceType: { type: String, default: 'xaas' },
})

const { t } = useI18n()

const open    = ref(false)
const loading = ref(false)
const files   = ref({})
const active  = ref(null)
const failed  = ref(false)
let loadedFor = null

async function load() {
  const key = `${props.runtime}:${props.serviceType}`
  if (!props.runtime || loadedFor === key) return
  loading.value = true
  failed.value  = false
  try {
    const { data } = await runtimesApi.template(props.runtime, { service_type: props.serviceType })
    files.value  = data.files ?? {}
    active.value = Object.keys(files.value)[0] ?? null
    loadedFor    = key
  } catch {
    files.value = {}
    failed.value = true
  } finally {
    loading.value = false
  }
}

watch(open, (v) => { if (v) load() })
watch(() => [props.runtime, props.serviceType], () => { if (open.value) load() })

const names = computed(() => Object.keys(files.value))
</script>

<template>
  <section v-if="runtime" class="-mt-2">
    <button type="button" class="flex items-center gap-1.5 text-xs text-gray-400 hover:text-gray-100 transition-colors" @click="open = !open">
      <FileCode2 class="w-3.5 h-3.5" />{{ t('runtimes.picker.starterCode') }}
      <ChevronDown class="w-3.5 h-3.5 transition-transform" :class="open && 'rotate-180'" />
    </button>
    <div v-if="open" class="mt-2 rounded-lg border border-gray-800 overflow-hidden">
      <div v-if="loading" class="py-6 text-center text-xs text-gray-400">{{ t('common.loading') }}</div>
      <p v-else-if="failed || !names.length" class="py-4 px-3 text-xs text-gray-400">{{ t('runtimes.picker.starterCodeEmpty') }}</p>
      <template v-else>
        <div v-if="names.length > 1" class="flex gap-1 px-2 py-1.5 border-b border-gray-800 bg-gray-900/60">
          <button v-for="n in names" :key="n" type="button"
            class="px-2 py-0.5 rounded text-[11px] font-mono"
            :class="active === n ? 'bg-sky-900/50 text-sky-300' : 'text-gray-400 hover:text-gray-100'"
            @click="active = n">{{ n }}</button>
        </div>
        <p v-else class="px-3 py-1 text-[11px] font-mono text-gray-400 border-b border-gray-800 bg-gray-900/60">{{ active }}</p>
        <MonacoEditor :model-value="files[active] ?? ''" :filename="active ?? ''" :runtime="runtime" height="200px" readonly />
      </template>
    </div>
  </section>
</template>

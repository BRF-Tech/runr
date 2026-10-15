<script setup>
// Model card of a Hub repository: summary, size, parameters, license, gated flag, files and the
// engines RunR can serve it with; one click opens the deploy dialog.
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Lock, X, Rocket, Library, ExternalLink } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useToast } from '@/composables/useToast'
import { formatBytes, formatCount, formatParameters } from '@/utils/ai'

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  modelId:            { type: String, required: true },
  canDeploy:          { type: Boolean, default: false },
  hubTokenConfigured: { type: Boolean, default: false },
})
const emit = defineEmits(['close', 'deploy', 'added'])

const info    = ref(null)
const loading = ref(false)
const error   = ref(null)
const adding  = ref(false)
const showFiles = ref(false)

async function load() {
  loading.value = true
  error.value = null
  info.value = null
  try {
    const { data } = await aiApi.hubInfo(props.modelId)
    info.value = data
  } catch (e) {
    error.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}
watch(() => props.modelId, load, { immediate: true })

const gated = computed(() => !!(info.value?.gated || info.value?.private))
const warnings = computed(() => info.value?.warnings ?? [])

async function addToLibrary() {
  adding.value = true
  try {
    const { data } = await aiApi.createModel({ source: 'hub', hub_id: info.value.id, revision: info.value.sha || undefined })
    toast.success(t('ai.library.added', { name: data?.name ?? info.value.id }))
    emit('added', data)
  } catch (e) {
    toast.error(e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error'))
  } finally {
    adding.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end" data-testid="hub-drawer">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-xs" @click="emit('close')" />
    <aside class="relative w-full max-w-xl h-full bg-gray-950 border-l border-gray-800 flex flex-col">
      <div class="flex items-center justify-between px-5 py-4 border-b border-gray-800">
        <div class="min-w-0">
          <p class="text-[10px] text-gray-500 uppercase tracking-wider">{{ t('ai.hub.modelCard') }}</p>
          <h3 class="font-mono text-sm text-gray-100 truncate">{{ modelId }}</h3>
        </div>
        <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
        <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
        <p v-else-if="error" class="text-xs text-red-400">{{ error }}</p>

        <template v-else-if="info">
          <div class="flex flex-wrap items-center gap-2 text-[11px]">
            <span v-if="info.task" class="px-1.5 py-0.5 rounded-sm bg-gray-800 text-gray-300">{{ t(`ai.tasks.${info.task}`, info.task) }}</span>
            <span v-if="info.library" class="px-1.5 py-0.5 rounded-sm bg-gray-800 font-mono text-gray-300">{{ info.library }}</span>
            <span v-if="info.license" class="px-1.5 py-0.5 rounded-sm bg-gray-800 text-gray-300">{{ t('ai.hub.license') }}: {{ info.license }}</span>
            <span v-if="gated" class="flex items-center gap-1 px-1.5 py-0.5 rounded-sm bg-amber-900/40 border border-amber-800/40 text-amber-300" data-testid="hub-gated">
              <Lock class="w-3 h-3" />{{ t('ai.hub.gated') }}
            </span>
          </div>

          <div class="grid grid-cols-4 gap-2">
            <div class="rounded-lg border border-gray-800 bg-gray-900/60 p-2.5">
              <p class="text-[10px] text-gray-500 uppercase">{{ t('ai.hub.size') }}</p>
              <p class="font-mono text-sm text-gray-100" data-testid="hub-size">{{ formatBytes(info.size_bytes) }}</p>
            </div>
            <div class="rounded-lg border border-gray-800 bg-gray-900/60 p-2.5">
              <p class="text-[10px] text-gray-500 uppercase">{{ t('ai.hub.parameters') }}</p>
              <p class="font-mono text-sm text-gray-100">{{ formatParameters(info.parameters) }}</p>
            </div>
            <div class="rounded-lg border border-gray-800 bg-gray-900/60 p-2.5">
              <p class="text-[10px] text-gray-500 uppercase">{{ t('ai.hub.downloads') }}</p>
              <p class="font-mono text-sm text-gray-100">{{ formatCount(info.downloads) }}</p>
            </div>
            <div class="rounded-lg border border-gray-800 bg-gray-900/60 p-2.5">
              <p class="text-[10px] text-gray-500 uppercase">{{ t('ai.hub.likes') }}</p>
              <p class="font-mono text-sm text-gray-100">{{ formatCount(info.likes) }}</p>
            </div>
          </div>

          <p v-if="gated && !hubTokenConfigured" class="text-xs text-amber-300/90 bg-amber-900/20 rounded-sm px-3 py-2">{{ t('ai.hub.gatedNoToken') }}</p>
          <p v-else-if="gated" class="text-xs text-gray-400">{{ t('ai.hub.gatedWithToken') }}</p>
          <p v-for="w in warnings" :key="w" class="text-xs text-amber-300/90 bg-amber-900/20 rounded-sm px-3 py-2">{{ t(`ai.hub.warnings.${w}`, w) }}</p>

          <!-- Engines -->
          <section>
            <p class="section-title">{{ t('ai.hub.engines') }}</p>
            <div v-if="info.engines?.length" class="space-y-1.5">
              <div v-for="e in info.engines" :key="e.engine" class="flex items-center gap-2 text-xs" :data-hub-engine="e.engine">
                <span class="font-medium text-gray-100 w-28">{{ t(`ai.engines.${e.engine}`, e.engine) }}</span>
                <span class="font-mono text-gray-400">{{ (e.devices ?? []).map(d => d.toUpperCase()).join(' · ') }}</span>
                <span class="text-gray-500">{{ t(`ai.apis.${e.api}`, e.api ?? '') }}</span>
                <span v-if="e.recommended" class="ml-auto px-1.5 py-0.5 rounded-sm text-[10px] bg-emerald-900/40 border border-emerald-800/40 text-emerald-300">{{ t('ai.hub.recommended') }}</span>
              </div>
            </div>
            <p v-else class="text-xs text-gray-400">{{ t('ai.hub.customOnly') }}</p>
            <p v-if="info.suggested" class="text-[11px] text-gray-400 mt-2">
              {{ t('ai.hub.suggested', {
                engine: t(`ai.engines.${info.suggested.engine}`, info.suggested.engine ?? ''),
                device: (info.suggested.device ?? '').toUpperCase(),
                memory: info.suggested.memory_mb ?? '—',
              }) }}
            </p>
          </section>

          <!-- Card summary -->
          <section v-if="info.card_summary">
            <p class="section-title">{{ t('ai.hub.summary') }}</p>
            <p class="text-xs text-gray-300 whitespace-pre-wrap leading-relaxed" data-testid="hub-card-summary">{{ info.card_summary }}</p>
          </section>

          <!-- Files -->
          <section v-if="info.files?.length">
            <button class="section-title w-full text-left" @click="showFiles = !showFiles">
              {{ t('ai.hub.files', { n: info.files.length }) }}
            </button>
            <ul v-if="showFiles" class="space-y-0.5 max-h-48 overflow-y-auto">
              <li v-for="f in info.files" :key="f.name" class="flex justify-between text-[11px] font-mono text-gray-400">
                <span class="truncate">{{ f.name }}</span><span class="shrink-0 ml-3">{{ formatBytes(f.size) }}</span>
              </li>
            </ul>
          </section>

          <p class="text-[11px] text-gray-500 font-mono">
            {{ t('ai.hub.revision') }}: {{ (info.sha || '').slice(0, 12) || 'main' }}
          </p>
          <a :href="`https://huggingface.co/${info.id}`" target="_blank" rel="noopener noreferrer"
            class="inline-flex items-center gap-1 text-[11px] text-gray-400 hover:text-gray-100">
            <ExternalLink class="w-3 h-3" />{{ t('ai.hub.openOnHub') }}
          </a>
        </template>
      </div>

      <div v-if="info && canDeploy" class="flex items-center justify-end gap-2 px-5 py-3 border-t border-gray-800">
        <button class="btn-secondary text-xs" :disabled="adding" data-testid="hub-add-library" @click="addToLibrary">
          <Library class="w-3.5 h-3.5" />{{ t('ai.hub.addToLibrary') }}
        </button>
        <button class="btn-primary text-xs" data-testid="hub-deploy" @click="emit('deploy', info)">
          <Rocket class="w-3.5 h-3.5" />{{ t('ai.hub.deploy') }}
        </button>
      </div>
    </aside>
  </div>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-2 border-b border-gray-800/60 pb-1.5;
}
</style>

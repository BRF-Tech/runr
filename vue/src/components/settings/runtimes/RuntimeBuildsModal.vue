<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { Hammer, RefreshCw } from 'lucide-vue-next'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import { runtimesApi } from '@/api/runtimes'
import { useSettingsStore } from '@/stores/settings'
import { isRegionReady, runtimeAvailableIn } from '@/composables/useProjectForm'
import { apiErrorMessage, builtVersions } from '@/composables/useRuntimesManager'

const props = defineProps({ runtime: { type: Object, required: true } })
const emit  = defineEmits(['close'])
const { t, locale } = useI18n()
const settingsStore = useSettingsStore()

const builds   = ref([])
const loading  = ref(true)
const error    = ref(null)
const starting = ref(null) // "<version>:<regionId>" being submitted
let timer = null

const ACTIVE = ['pending', 'building']

async function load() {
  try {
    const { data } = await runtimesApi.builds(props.runtime.id)
    builds.value = data.data ?? []
    error.value  = null
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
    schedule()
  }
}

// Poll while a build runs
function schedule() {
  clearTimeout(timer)
  if (builds.value.some(b => ACTIVE.includes(b.status))) timer = setTimeout(load, 5000)
}

onMounted(() => {
  if (!settingsStore.loaded) settingsStore.fetch()
  load()
})
onBeforeUnmount(() => clearTimeout(timer))

const regions = computed(() => (settingsStore.data.regions ?? [])
  .filter(r => isRegionReady(r) && runtimeAvailableIn(props.runtime, r.id)))

/** One row per built version × region the runtime is offered in (with or without a build yet). */
const rows = computed(() => {
  const list = []
  for (const v of builtVersions(props.runtime)) {
    for (const r of regions.value) {
      const b = builds.value.find(x => x.version === v.version && x.knative_region_id === r.id)
      list.push({ key: `${v.version}:${r.id}`, version: v.version, regionId: r.id, regionName: r.name, build: b ?? null })
    }
  }
  // Builds of regions no longer listed (e.g. removed from the runtime) stay visible
  for (const b of builds.value) {
    if (!list.some(x => x.version === b.version && x.regionId === b.knative_region_id)) {
      list.push({ key: `${b.version}:${b.knative_region_id}`, version: b.version, regionId: b.knative_region_id, regionName: b.region_name ?? b.knative_region_id, build: b, stale: true })
    }
  }
  return list
})

async function build(row) {
  starting.value = row.key
  try {
    const { data } = await runtimesApi.build(props.runtime.id, { knative_region_id: row.regionId, version: row.version })
    if (Array.isArray(data.data)) {
      for (const b of data.data) {
        const idx = builds.value.findIndex(x => x.version === b.version && x.knative_region_id === b.knative_region_id)
        if (idx === -1) builds.value.push(b)
        else builds.value[idx] = b
      }
    }
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    starting.value = null
  }
}

const statusStyle = {
  pending:  'border-gray-700/60 bg-gray-800/60 text-gray-300',
  building: 'border-sky-800/50 bg-sky-950/40 text-sky-300',
  ready:    'border-emerald-800/50 bg-emerald-950/30 text-emerald-400',
  failed:   'border-red-800/50 bg-red-950/30 text-red-400',
}

function fmtTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-4xl max-h-[90vh] overflow-y-auto">
      <div class="card">
        <div class="flex items-center justify-between gap-4 mb-1">
          <div class="flex items-center gap-3">
            <RuntimeIcon :runtime="runtime.id" :size="22" />
            <h2 class="text-base font-semibold text-gray-100">{{ t('runtimes.builds.title', { name: runtime.name }) }}</h2>
          </div>
          <div class="flex items-center gap-3">
            <button class="text-gray-400 hover:text-gray-100" :title="t('runtimes.actions.refresh')" @click="load"><RefreshCw class="w-4 h-4" /></button>
            <button class="text-gray-400 hover:text-gray-100 transition-colors" @click="emit('close')">{{ t('common.close') }}</button>
          </div>
        </div>
        <p class="text-xs text-gray-400 mb-5">{{ t('runtimes.builds.description') }}</p>

        <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>
        <div v-if="loading" class="py-12 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
        <p v-else-if="!rows.length" class="text-sm text-gray-400 py-6 text-center">{{ t('runtimes.builds.empty') }}</p>

        <table v-else class="w-full text-sm">
          <thead>
            <tr class="text-left text-[11px] uppercase tracking-wider text-gray-500 border-b border-gray-800">
              <th class="py-2 pr-3 font-medium">{{ t('runtimes.builds.version') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('runtimes.builds.region') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('runtimes.builds.status') }}</th>
              <th class="py-2 pr-3 font-medium">{{ t('runtimes.builds.image') }}</th>
              <th class="py-2 font-medium"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.key" class="border-b border-gray-800/60 align-top">
              <td class="py-2.5 pr-3 font-mono text-xs text-gray-200">{{ row.version }}</td>
              <td class="py-2.5 pr-3 font-mono text-xs text-gray-300">{{ row.regionName }}</td>
              <td class="py-2.5 pr-3">
                <span class="px-1.5 py-0.5 rounded-sm border text-[11px] font-medium"
                  :class="statusStyle[row.build?.status] ?? 'border-gray-800 text-gray-500'">
                  {{ row.build ? t(`runtimes.builds.status_${row.build.status}`) : t('runtimes.builds.status_none') }}
                </span>
                <p v-if="row.build?.updated_at" class="text-[10px] text-gray-500 mt-1">{{ fmtTime(row.build.updated_at) }}</p>
              </td>
              <td class="py-2.5 pr-3 text-xs min-w-0">
                <p v-if="row.build?.image" class="font-mono text-[11px] text-gray-400 break-all">{{ row.build.image }}</p>
                <p v-if="row.build?.error" class="text-[11px] text-red-400 whitespace-pre-line wrap-break-word max-w-md">{{ row.build.error }}</p>
              </td>
              <td class="py-2.5 text-right">
                <button v-if="!row.stale"
                  class="btn-secondary text-xs inline-flex items-center gap-1.5"
                  :disabled="starting === row.key || ACTIVE.includes(row.build?.status)"
                  @click="build(row)"
                >
                  <Hammer class="w-3.5 h-3.5" />
                  {{ starting === row.key ? t('runtimes.builds.starting') : (row.build ? t('runtimes.builds.rebuild') : t('runtimes.builds.build')) }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
// Built-in runtimes (runtimes.manage, GET / PUT /runtime-catalog, docs/runtimes.md#built-in-runtimes):
// which runtimes of RunR's catalog the platform offers — every one, also those the catalog gains
// later, or only a selection. A runtime left out is not offered for new projects; its projects
// keep deploying. Replaces EXECUTOR_RUNTIMES: until saved, that variable is the value.
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Boxes, Loader2 } from 'lucide-vue-next'
import { runtimeCatalogApi } from '@/api/runtimeSources'
import { apiErrorMessage } from '@/composables/useRuntimesManager'
import { useRuntimesStore } from '@/stores/runtimes'
import { useToast } from '@/composables/useToast'

const emit = defineEmits(['saved'])
const { t } = useI18n()
const toast = useToast()
const runtimesStore = useRuntimesStore()

const state    = ref(null)
const loading  = ref(true)
const saving   = ref(false)
const error    = ref(null)
const mode     = ref('all')      // all | selected
const selected = ref([])

function apply(data) {
  state.value    = data
  mode.value     = data.builtin_runtimes === null || data.builtin_runtimes === undefined ? 'all' : 'selected'
  selected.value = mode.value === 'all'
    ? (data.runtimes ?? []).map(r => r.id)
    : [...data.builtin_runtimes]
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await runtimeCatalogApi.get()
    apply(data.data ?? data)
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}

onMounted(load)

const runtimes = computed(() => state.value?.runtimes ?? [])

const dirty = computed(() => {
  if (!state.value) return false
  const saved = state.value.builtin_runtimes
  if (mode.value === 'all') return saved !== null && saved !== undefined
  if (saved === null || saved === undefined) return true
  return [...selected.value].sort().join(',') !== [...saved].sort().join(',')
})

function toggle(id) {
  selected.value = selected.value.includes(id) ? selected.value.filter(x => x !== id) : [...selected.value, id]
}

async function save() {
  saving.value = true
  error.value = null
  try {
    const { data } = await runtimeCatalogApi.update(mode.value === 'all' ? null : [...selected.value])
    apply(data.data ?? data)
    toast.success(t('runtimes.catalog.saved'))
    runtimesStore.refresh()
    emit('saved')
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    saving.value = false
  }
}

defineExpose({ load, save, mode, selected })
</script>

<template>
  <div class="card" data-tour="runtime-catalog-card" data-test="builtin-runtimes">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3 pb-3 border-b border-gray-800">
      <div class="flex items-center gap-2">
        <Boxes class="w-4 h-4" style="color:rgb(var(--brand-500))" />
        <h4 class="text-sm font-semibold text-gray-100">{{ t('runtimes.catalog.title') }}</h4>
      </div>
      <span v-if="state" class="text-[11px] text-gray-400" data-test="builtin-runtimes-mode">
        {{ state.mode === 'bundle' ? t('runtimes.sources.catalogBundle') : t('runtimes.sources.catalogSource') }}
      </span>
    </div>
    <p class="text-xs text-gray-400 mb-3">{{ t('runtimes.catalog.description') }}</p>

    <div v-if="loading && !state" class="py-6 text-center text-gray-400 text-xs">{{ t('common.loading') }}</div>
    <template v-else-if="state">
      <div class="space-y-2 mb-3">
        <label class="flex items-start gap-2 text-sm text-gray-200 cursor-pointer">
          <input v-model="mode" type="radio" value="all" class="accent-brand-500 mt-1" data-test="builtin-runtimes-all" />
          <span>{{ t('runtimes.catalog.all') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-gray-200 cursor-pointer">
          <input v-model="mode" type="radio" value="selected" class="accent-brand-500 mt-1" data-test="builtin-runtimes-selected" />
          <span>{{ t('runtimes.catalog.selected') }}</span>
        </label>
      </div>

      <div v-if="mode === 'selected'" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-1.5 mb-3" data-test="builtin-runtimes-list">
        <label v-for="r in runtimes" :key="r.id" class="flex items-center gap-2 text-xs text-gray-300 cursor-pointer rounded-md border border-gray-800 px-2 py-1.5" :data-builtin="r.id">
          <input type="checkbox" class="accent-brand-500" :checked="selected.includes(r.id)" @change="toggle(r.id)" />
          <span class="truncate">{{ r.name }}</span>
          <span class="font-mono text-[10px] text-gray-500">{{ r.id }}</span>
          <span v-if="!r.in_bundle" class="ml-auto text-[9px] text-gray-500" :title="t('runtimes.catalog.notInBundleHint')">{{ t('runtimes.catalog.notInBundle') }}</span>
        </label>
        <p v-if="!selected.length" class="col-span-full text-[11px] text-yellow-400">{{ t('runtimes.catalog.noneSelected') }}</p>
      </div>
      <p v-else class="text-[11px] text-gray-400 mb-3">{{ t('runtimes.catalog.offered', { n: runtimes.filter(r => r.offered).length, total: runtimes.length }) }}</p>

      <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-[11px]" :class="state.saved ? 'text-gray-400' : 'text-yellow-400'" data-test="builtin-runtimes-saved">
          {{ dirty ? t('runtimes.catalog.unsaved') : state.saved ? t('runtimes.catalog.savedState') : t('runtimes.catalog.notSaved') }}
        </p>
        <button type="button" class="btn-primary text-xs" :disabled="saving || (!dirty && state.saved)" data-test="builtin-runtimes-save" @click="save">
          <Loader2 v-if="saving" class="w-3.5 h-3.5 animate-spin" />{{ t('common.save') }}
        </button>
      </div>
    </template>
    <p v-if="error" class="text-xs text-red-400 mt-2">{{ error }}</p>
  </div>
</template>

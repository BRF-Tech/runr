<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import { runtimeLabel } from '@/utils/runtimes'
import { isWasmRuntime } from '@/utils/wasm'
import { day, lifecycleClasses, runtimeBadgeTitle, runtimeState, versionState, versionTitle } from '@/utils/runtimeLifecycle'

const { t } = useI18n()

const props = defineProps({
  // Catalog entries ({ id, name, description, source, unsupported, retired, lifecycle, ... }) or runtime ids
  runtimes: { type: Array, required: true },
  // Versions that have not ended; `ended` marks the project's own one after it ended (edit)
  runtimeVersions: { type: Array, default: () => [] },
  selectedRuntime: { type: String, default: null },
  selectedVersion: { type: String, default: null },
  readonly: { type: Boolean, default: false },
  triggerType: { type: String, default: null },
})

defineEmits(['update:runtime', 'update:version'])

const items = computed(() => props.runtimes.map(r => (typeof r === 'string' ? { id: r } : r)))

const gridClass = computed(() => {
  const n = items.value.length
  if (n === 2) return 'grid-cols-2'
  if (n === 3) return 'grid-cols-3'
  return 'grid-cols-2 sm:grid-cols-4'
})

function isDisabled(r) {
  return props.readonly || !!r.unsupported || !!r.retired
}

// Lifecycle (docs/runtimes.md#lifecycle): a deprecated runtime is marked; one that left its source
// or was sunset can not be picked for a new project
function title(r) {
  if (r.unsupported) return t('runtimes.picker.unsupportedTrigger', { trigger: t(`create.triggerTypes.${props.triggerType ?? 'http'}`) })
  if (r.retired) {
    return runtimeState(r) === 'orphaned'
      ? t('runtimes.picker.orphaned')
      : t('runtimes.picker.sunset', { date: day(r.lifecycle?.sunset_at) })
  }
  const lifecycle = runtimeState(r) === 'deprecated' ? runtimeBadgeTitle(r, t, runtimeLabel) : ''
  return [r.description || '', lifecycle].filter(Boolean).join('\n')
}

/** The lifecycle mark of a runtime tile (null when active). */
function runtimeMark(r) {
  const state = runtimeState(r)
  if (state === 'active') return null
  return { state, label: t(`runtimes.lifecycle.states.${state}`), classes: lifecycleClasses(state) }
}

/** The lifecycle mark of a version: deprecated, or ended (edit shows the project's own one). */
function versionMark(v) {
  const state = versionState(v)
  if (state === 'active') return null
  return { state, label: t(`runtimes.lifecycle.versionStates.${state}`), classes: lifecycleClasses(state) }
}
</script>

<template>
  <section>
    <p class="section-title">{{ t('create.sections.lang') }}</p>
    <p v-if="!items.length" class="text-xs text-gray-400">{{ t('runtimes.picker.none') }}</p>
    <div v-else class="grid gap-2" :class="gridClass">
      <button
        v-for="r in items"
        :key="r.id"
        :data-runtime="r.id"
        type="button"
        class="relative flex flex-col items-center gap-1.5 py-3 px-2 rounded-lg border text-sm font-medium transition-all"
        :class="selectedRuntime === r.id
          ? 'text-white border-transparent shadow-lg'
          : 'bg-gray-900/60 border-gray-700/60 text-gray-400' + (isDisabled(r) ? ' opacity-50 cursor-not-allowed' : ' hover:border-gray-500 hover:text-gray-100')"
        :style="selectedRuntime === r.id ? 'background:rgb(var(--brand-600)/0.25);border-color:rgb(var(--brand-500)/0.5);box-shadow:0 0 12px rgb(var(--brand-600)/0.15)' : ''"
        :disabled="isDisabled(r)"
        :title="title(r)"
        @click="!isDisabled(r) && $emit('update:runtime', r.id)"
      >
        <span
          v-if="r.source && r.source !== 'builtin'"
          class="absolute top-1 right-1 px-1 rounded-sm text-[9px] font-mono leading-tight border"
          :class="r.source === 'custom' ? 'border-brand-800/60 text-brand-400 bg-brand-900/40' : 'border-brand-500/60 text-brand-400 bg-transparent'"
        >{{ t(`runtimes.source.${r.source}`) }}</span>
        <span
          v-if="isWasmRuntime(r)"
          class="wasm-badge absolute top-1 left-1 px-1 rounded-sm text-[9px] font-mono leading-tight border border-gray-700 text-brand-300 bg-gray-800/80"
          :title="t('wasm.picker.badgeTitle')"
        >{{ t('wasm.picker.badge') }}</span>
        <RuntimeIcon :runtime="r.id" :size="28" />
        <span class="runtime-name font-mono text-xs truncate max-w-full">{{ r.name ?? runtimeLabel(r.id) }}</span>
        <span
          v-if="runtimeMark(r)"
          class="runtime-lifecycle px-1 rounded-sm text-[9px] leading-tight border"
          :class="runtimeMark(r).classes"
          :data-lifecycle="runtimeMark(r).state"
        >{{ runtimeMark(r).label }}</span>
      </button>
    </div>

    <div v-if="runtimeVersions.length" class="mt-3">
      <p class="section-title">{{ t('create.sections.version') }}</p>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="v in runtimeVersions"
          :key="v.version"
          :data-version="v.version"
          type="button"
          class="px-3 py-1.5 rounded-md border text-sm font-mono transition-all"
          :class="selectedVersion === v.version
            ? 'text-white border-transparent'
            : 'bg-gray-800 border-gray-700 text-gray-400' + (readonly || v.ended ? ' opacity-50' : ' hover:border-gray-500')"
          :style="selectedVersion === v.version ? 'background-color:rgb(var(--brand-600));border-color:rgb(var(--brand-600))' : ''"
          :disabled="readonly || v.ended"
          :title="versionTitle(v, t)"
          @click="!readonly && !v.ended && $emit('update:version', v.version)"
        >
          <span :class="v.ended && 'line-through'">{{ v.version }}</span>
          <span v-if="v.is_default" class="ml-1 text-[10px] opacity-60">{{ t('create.fields.defaultBadge') }}</span>
          <span v-if="v.gpu_required" class="ml-1 text-[10px] text-brand-400">GPU</span>
          <span
            v-if="versionMark(v)"
            class="version-lifecycle ml-1 px-1 rounded-sm border text-[10px] font-sans"
            :class="versionMark(v).classes"
            :data-lifecycle="versionMark(v).state"
          >{{ versionMark(v).label }}</span>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>

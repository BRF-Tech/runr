<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Eye, Pencil, Trash2, Rocket, Hammer, Boxes, RefreshCw, Link2 } from 'lucide-vue-next'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import RuntimeEditorModal from '@/components/settings/runtimes/RuntimeEditorModal.vue'
import RuntimeManifestModal from '@/components/settings/runtimes/RuntimeManifestModal.vue'
import RuntimeTestDeployModal from '@/components/settings/runtimes/RuntimeTestDeployModal.vue'
import RuntimeBuildsModal from '@/components/settings/runtimes/RuntimeBuildsModal.vue'
import RuntimeSourcesPanel from '@/components/settings/runtimes/RuntimeSourcesPanel.vue'
import RuntimeLifecycleBadge from '@/components/settings/runtimes/RuntimeLifecycleBadge.vue'
import RuntimeVersionChip from '@/components/settings/runtimes/RuntimeVersionChip.vue'
import { useRuntimesManager, builtVersions, isSourceManaged } from '@/composables/useRuntimesManager'
import { runtimeState, versionState } from '@/utils/runtimeLifecycle'
import { useSettingsStore } from '@/stores/settings'

const { t } = useI18n()
const settingsStore = useSettingsStore()

const {
  items, loading, error, canManage, canUseCustom,
  load, canEdit, canDelete, canTest, toggleEnabled, remove, onSaved,
} = useRuntimesManager()

onMounted(() => {
  load()
  if (!settingsStore.loaded) settingsStore.fetch()
})

// Runtimes (the catalog) and Sources (where runtimes come from, docs/runtimes.md#runtime-sources):
// whoever sees the page sees both — runtime managers every source, custom-runtime users their
// organization's
const view = ref('runtimes')
// A change on the Sources tab (a read, a removed source) changes the runtimes: listed anew
let sourcesChanged = false
function showView(next) {
  if (next === view.value) return
  view.value = next
  if (next === 'runtimes' && sourcesChanged) {
    sourcesChanged = false
    load()
  }
}

const regionNames = computed(() => Object.fromEntries((settingsStore.data.regions ?? []).map(r => [r.id, r.name])))

const sorted = computed(() => {
  const order = { builtin: 0, platform: 1, custom: 2 }
  return [...items.value].sort((a, b) => (order[a.source] ?? 9) - (order[b.source] ?? 9) || a.name.localeCompare(b.name))
})

// The tour's lifecycle step points at the first lifecycle mark: a runtime's badge, else a version's
const lifecycleTourId = computed(() => sorted.value.find(r => runtimeState(r) !== 'active')?.id ?? null)
const versionsTourId = computed(() => lifecycleTourId.value
  ? null
  : sorted.value.find(r => r.versions.some(v => versionState(v) !== 'active'))?.id ?? null)
const managedTourId = computed(() => sorted.value.find(isSourceManaged)?.id ?? null)

const editorTarget   = ref(undefined) // undefined = closed, null = add, object = edit
const manifestTarget = ref(null)
const testTarget     = ref(null)
const buildsTarget   = ref(null)

function regionsLabel(r) {
  if (!Array.isArray(r.regions)) return t('runtimes.list.allRegions')
  if (!r.regions.length) return t('runtimes.list.noRegions')
  return r.regions.map(id => regionNames.value[id] ?? id).join(', ')
}

const sourceStyle = {
  builtin:  'border-gray-700/60 bg-gray-800/60 text-gray-300',
  platform: 'border-brand-800/50 bg-brand-900/40 text-brand-400',
  custom:   'border-brand-500/60 bg-transparent text-brand-400',
}

// A synced runtime opens with its manifest read-only (the source changes it): the title says so
function editTitle(r) {
  return isSourceManaged(r) ? t('runtimes.actions.editManaged', { source: r.source_name ?? '' }) : t('runtimes.actions.edit')
}

function onEditorSaved({ runtime, created }) {
  editorTarget.value = undefined
  onSaved(runtime, created)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between gap-4 mb-5">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('runtimes.title') }}</h3>
          <p class="text-xs text-gray-400">{{ canManage ? t('runtimes.subtitleManage') : t('runtimes.subtitleCustom') }}</p>
        </div>
      </div>
      <div v-if="view === 'runtimes'" class="flex items-center gap-2">
        <button class="btn-secondary flex items-center gap-1.5 text-xs" :disabled="loading" :title="t('runtimes.actions.refresh')" @click="load">
          <RefreshCw class="w-3.5 h-3.5" :class="loading && 'animate-spin'" />
        </button>
        <button v-if="canUseCustom" class="btn-primary flex items-center gap-1.5" data-tour="runtimes-add" @click="editorTarget = null">
          <Plus class="w-4 h-4" />{{ t('runtimes.actions.add') }}
        </button>
      </div>
    </div>

    <!-- Runtimes | Sources -->
    <div v-if="canUseCustom" class="flex items-center gap-1 mb-5" role="tablist" data-tour="runtimes-tabs" data-test="runtimes-views">
      <button
        type="button" role="tab" class="tab-pill" :class="view === 'runtimes' && 'is-active'"
        :aria-selected="view === 'runtimes'" data-runtimes-view="runtimes" @click="showView('runtimes')"
      >{{ t('runtimes.views.runtimes') }}</button>
      <button
        type="button" role="tab" class="tab-pill" :class="view === 'sources' && 'is-active'"
        :aria-selected="view === 'sources'" data-runtimes-view="sources" @click="showView('sources')"
      >{{ t('runtimes.views.sources') }}</button>
    </div>

    <RuntimeSourcesPanel v-if="view === 'sources'" @changed="sourcesChanged = true" />

    <template v-else>
      <p v-if="error" class="text-sm text-red-400 mb-4">{{ error }}</p>

      <div v-if="loading && !items.length" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

      <div v-else-if="!items.length" class="card py-12 text-center">
        <Boxes class="w-8 h-8 mx-auto mb-3 text-gray-500" />
        <p class="text-sm text-gray-400">{{ t('runtimes.list.empty') }}</p>
      </div>

      <div v-else class="space-y-2.5">
        <div
          v-for="(r, i) in sorted" :key="r.id"
          :data-runtime-row="r.id"
          :data-tour="i === 0 ? 'runtimes-list' : undefined"
          class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3 flex flex-wrap items-center gap-4"
          :class="!r.is_enabled && 'opacity-60'"
        >
          <!-- Identity -->
          <div class="flex items-center gap-3 min-w-[220px] flex-1">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0 bg-gray-800/60 border border-gray-700/50">
              <RuntimeIcon :runtime="r.id" :size="24" />
            </div>
            <div class="min-w-0">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="text-sm font-semibold text-gray-100 truncate">{{ r.name }}</span>
                <span class="font-mono text-[11px] text-gray-400">{{ r.id }}</span>
                <span class="px-1.5 py-0.5 rounded-sm border text-[10px] font-medium" :class="sourceStyle[r.source] ?? sourceStyle.builtin">{{ t(`runtimes.source.${r.source}`) }}</span>
                <span
                  v-if="isSourceManaged(r)"
                  class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm border border-gray-700/60 bg-gray-800/40 text-[10px] text-gray-300"
                  :title="t('runtimes.list.fromSourceHint', { source: r.source_name ?? '' })"
                  :data-tour="r.id === managedTourId ? 'runtimes-managed' : undefined"
                  data-test="runtime-from-source"
                ><Link2 class="w-3 h-3" />{{ t('runtimes.list.fromSource', { source: r.source_name ?? '' }) }}</span>
                <span class="px-1.5 py-0.5 rounded-sm border border-gray-700/60 bg-gray-800/40 text-[10px] font-mono text-gray-300">{{ t(`runtimes.mode.${r.run_mode}`) }}</span>
                <span v-if="r.compiled" class="px-1.5 py-0.5 rounded-sm border border-brand-800/50 bg-brand-900/30 text-[10px] font-medium text-brand-300">{{ t('runtimes.list.compiled') }}</span>
                <RuntimeLifecycleBadge :runtime="r" :data-tour="r.id === lifecycleTourId ? 'runtimes-lifecycle' : undefined" />
              </div>
              <p v-if="r.description" class="text-xs text-gray-400 truncate max-w-[460px]">{{ r.description }}</p>
            </div>
          </div>

          <!-- Versions -->
          <div class="flex flex-wrap gap-1 max-w-[300px]" :data-tour="r.id === versionsTourId ? 'runtimes-lifecycle' : undefined">
            <RuntimeVersionChip v-for="v in r.versions" :key="v.version" :version="v" />
          </div>

          <!-- Regions + usage -->
          <div class="text-xs text-gray-400 w-44 min-w-0">
            <p class="truncate" :title="regionsLabel(r)">{{ regionsLabel(r) }}</p>
            <p v-if="r.projects_count !== null" class="font-mono text-[11px]">{{ t('runtimes.list.projects', { n: r.projects_count }) }}</p>
          </div>

          <!-- Enabled toggle -->
          <div class="flex items-center gap-2 w-28">
            <button
              type="button"
              class="relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors"
              :class="[r.is_enabled ? 'bg-brand-500' : 'bg-gray-700', canEdit(r) ? 'cursor-pointer' : 'cursor-not-allowed opacity-50']"
              :disabled="!canEdit(r)"
              :title="r.is_enabled ? t('runtimes.actions.disable') : t('runtimes.actions.enable')"
              @click="canEdit(r) && toggleEnabled(r)"
            >
              <span class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-sm transition" :class="r.is_enabled ? 'translate-x-4' : 'translate-x-0'" />
            </button>
            <span class="text-[11px]" :class="r.is_enabled ? 'text-brand-500' : 'text-gray-400'">{{ r.is_enabled ? t('runtimes.list.enabled') : t('runtimes.list.disabled') }}</span>
          </div>

          <!-- Actions -->
          <div class="flex items-center gap-1 ml-auto">
            <button class="icon-btn" :title="t('runtimes.actions.view')" @click="manifestTarget = r"><Eye class="w-4 h-4" /></button>
            <button v-if="canEdit(r)" class="icon-btn" :title="editTitle(r)" data-test="runtime-edit" @click="editorTarget = r"><Pencil class="w-4 h-4" /></button>
            <button v-if="canTest(r)" class="icon-btn" :title="t('runtimes.actions.testDeploy')" @click="testTarget = r"><Rocket class="w-4 h-4" /></button>
            <button v-if="canEdit(r) && builtVersions(r).length" class="icon-btn" :title="t('runtimes.actions.builds')" @click="buildsTarget = r"><Hammer class="w-4 h-4" /></button>
            <button v-if="canDelete(r)" class="icon-btn hover:text-red-400!" :title="t('runtimes.actions.delete')" @click="remove(r)"><Trash2 class="w-4 h-4" /></button>
          </div>
        </div>
      </div>
    </template>

    <RuntimeEditorModal
      v-if="editorTarget !== undefined"
      :runtime="editorTarget"
      :can-manage="canManage"
      @close="editorTarget = undefined"
      @saved="onEditorSaved"
    />
    <RuntimeManifestModal v-if="manifestTarget" :runtime="manifestTarget" @close="manifestTarget = null" />
    <RuntimeTestDeployModal v-if="testTarget" :runtime="testTarget" @close="testTarget = null" />
    <RuntimeBuildsModal v-if="buildsTarget" :runtime="buildsTarget" @close="buildsTarget = null" />
  </div>
</template>

<style scoped>
@reference "../../assets/main.css";
.icon-btn {
  @apply p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors;
}
</style>

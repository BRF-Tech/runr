<script setup>
// Build & Run → Runtimes → Sources (docs/runtimes.md#runtime-sources): where runtimes come from —
// RunR's signed catalog (the built-in runtimes) and the OCI catalogs and Git repositories platform
// administrators connect for everyone, or organizations for themselves. Each source with its
// status, last reads, revision and runtimes; Add source, Sync now, Edit, Delete, on / off, and a
// detail with its problems (file + JSON path), its runtimes (on / off, lifecycle) and patches.
// Runtime managers also choose the Built-in runtimes the platform offers.
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, RefreshCw, Pencil, Trash2, ChevronDown, ChevronRight, GitBranch, Package, ShieldCheck, ShieldAlert, Info } from 'lucide-vue-next'
import RuntimeSourceModal from '@/components/settings/runtimes/RuntimeSourceModal.vue'
import RuntimeSourceDetail from '@/components/settings/runtimes/RuntimeSourceDetail.vue'
import BuiltinRuntimesCard from '@/components/settings/runtimes/BuiltinRuntimesCard.vue'
import { useRuntimeSources, sourceStatusClasses, shortRevision } from '@/composables/useRuntimeSources'

const emit = defineEmits(['changed'])
const { t } = useI18n()

const {
  sources, catalog, customEnabled, loading, loaded, error, forbidden, busy, expanded, details,
  canManage, canConnect, scope,
  load, canChange, sync, setEnabled, remove, toggleDetail, canToggleRuntime, setRuntimeEnabled, onSaved,
} = useRuntimeSources({ onChanged: () => emit('changed') })

onMounted(load)

const modalTarget = ref(undefined) // undefined = closed, null = add, object = edit

// RunR's catalog first, then the platform's sources, then the organizations'
const sorted = computed(() => [...sources.value].sort((a, b) =>
  Number(b.is_default) - Number(a.is_default)
  || (a.scope === b.scope ? 0 : a.scope === 'platform' ? -1 : 1)
  || a.name.localeCompare(b.name)))

const orgSources = computed(() => sources.value.some(s => s.scope === 'organization'))

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : t('runtimes.sources.never'))

function location(s) {
  if (s.kind !== 'git') return s.url
  return [s.url, s.ref ? `@${s.ref}` : null, s.path ? `/${s.path}` : null].filter(Boolean).join(' ')
}

function statusTitle(s) {
  return [s.error, ...(s.problems ?? []).slice(0, 3).map(p => `${p.file ?? p.runtime ?? ''} ${p.path}: ${p.message}`.trim())]
    .filter(Boolean).join('\n')
}

function catalogLabel() {
  return catalog.value?.mode === 'bundle' ? t('runtimes.sources.catalogBundle') : t('runtimes.sources.catalogSource')
}

function onModalSaved({ source, created }) {
  modalTarget.value = undefined
  onSaved(source, created)
}

defineExpose({ load, sources })
</script>

<template>
  <div data-test="runtime-sources">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
      <p class="text-xs text-gray-400 max-w-3xl leading-relaxed">{{ canManage ? t('runtimes.sources.introManage') : t('runtimes.sources.introOrganization') }}</p>
      <div class="flex items-center gap-2">
        <button class="btn-secondary flex items-center gap-1.5 text-xs" :disabled="loading" :title="t('runtimes.actions.refresh')" @click="load">
          <RefreshCw class="w-3.5 h-3.5" :class="loading && 'animate-spin'" />
        </button>
        <button v-if="canConnect" class="btn-primary flex items-center gap-1.5" data-tour="runtime-sources-add" data-test="runtime-sources-add" @click="modalTarget = null">
          <Plus class="w-4 h-4" />{{ t('runtimes.sources.actions.add') }}
        </button>
      </div>
    </div>

    <!-- Custom runtimes are off: an organization connects no sources -->
    <div v-if="forbidden" class="card flex items-start gap-3 text-sm text-gray-300" data-test="runtime-sources-forbidden">
      <Info class="w-4 h-4 mt-0.5 text-yellow-400 shrink-0" />
      <p>{{ t('runtimes.sources.customOff') }}</p>
    </div>

    <template v-else>
      <p v-if="canManage && !customEnabled && orgSources" class="text-xs text-yellow-400 mb-3 flex items-center gap-2" data-test="runtime-sources-custom-off">
        <Info class="w-3.5 h-3.5 shrink-0" />{{ t('runtimes.sources.customOffManage') }}
      </p>
      <p v-if="error" class="text-sm text-red-400 mb-4">{{ error }}</p>

      <div v-if="loading && !loaded" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

      <div v-else-if="!sources.length" class="card py-10 text-center" data-test="runtime-sources-empty">
        <GitBranch class="w-8 h-8 mx-auto mb-3 text-gray-500" />
        <p class="text-sm text-gray-400">{{ t('runtimes.sources.empty') }}</p>
      </div>

      <div v-else class="space-y-2.5" data-tour="runtime-sources-list">
        <div
          v-for="(s, i) in sorted" :key="s.id"
          class="rounded-xl border border-gray-800 bg-gray-900/60"
          :class="!s.is_enabled && 'opacity-70'"
          :data-source-row="s.id"
        >
          <div class="px-4 py-3 flex flex-wrap items-center gap-4">
            <!-- Identity -->
            <div class="flex items-start gap-3 min-w-[280px] flex-1">
              <button
                type="button"
                class="mt-2 text-gray-400 hover:text-gray-100 shrink-0"
                :title="expanded[s.id] ? t('runtimes.sources.actions.hideDetail') : t('runtimes.sources.actions.showDetail')"
                :aria-expanded="!!expanded[s.id]"
                :data-tour="i === 0 ? 'runtime-sources-detail' : undefined"
                data-test="runtime-source-expand"
                @click="toggleDetail(s)"
              >
                <ChevronDown v-if="expanded[s.id]" class="w-4 h-4" /><ChevronRight v-else class="w-4 h-4" />
              </button>
              <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 bg-gray-800/60 border border-gray-700/50">
                <GitBranch v-if="s.kind === 'git'" class="w-4 h-4 text-brand-400" />
                <Package v-else class="w-4 h-4 text-brand-400" />
              </div>
              <div class="min-w-0 space-y-1">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="text-sm font-semibold text-gray-100 truncate">{{ s.name }}</span>
                  <span
                    class="px-1.5 py-0.5 rounded-sm border text-[10px] font-medium"
                    :class="s.scope === 'platform' ? 'border-brand-800/50 bg-brand-900/40 text-brand-400' : 'border-brand-500/60 bg-transparent text-brand-400'"
                    data-test="runtime-source-scope"
                  >{{ t(`runtimes.sources.scope.${s.scope}`) }}</span>
                  <span class="px-1.5 py-0.5 rounded-sm border border-gray-700/60 bg-gray-800/40 text-[10px] font-mono text-gray-300">{{ t(s.kind === 'git' ? 'runtimes.sources.kind.git' : 'runtimes.sources.kind.oci') }}</span>
                  <span v-if="s.is_default" class="px-1.5 py-0.5 rounded-sm border border-gray-700/60 bg-gray-800/60 text-[10px] text-gray-300" data-test="runtime-source-catalog">{{ catalogLabel() }}</span>
                  <span
                    class="px-1.5 py-0.5 rounded-sm border text-[10px] font-medium"
                    :class="sourceStatusClasses(s.status)"
                    :title="statusTitle(s)"
                    :data-tour="i === 0 ? 'runtime-sources-status' : undefined"
                    data-test="runtime-source-status"
                  >{{ t(`runtimes.sources.status.${s.status}`) }}</span>
                </div>
                <p class="font-mono text-[11px] text-gray-400 break-all">{{ location(s) || t('runtimes.sources.notConfigured') }}</p>
                <p class="text-[11px] text-gray-400 flex flex-wrap gap-x-3 gap-y-0.5">
                  <span>{{ t('runtimes.sources.checked', { date: fmt(s.checked_at) }) }}</span>
                  <span>{{ t('runtimes.sources.synced', { date: fmt(s.synced_at) }) }}</span>
                  <span v-if="s.revision" class="font-mono" :title="s.revision">{{ t('runtimes.sources.revision', { revision: shortRevision(s.revision) }) }}</span>
                  <span>{{ t('runtimes.sources.runtimesCount', { n: s.runtimes_count ?? 0 }) }}</span>
                  <span v-if="s.signature_verified" class="inline-flex items-center gap-1 text-emerald-400" data-test="runtime-source-signed"><ShieldCheck class="w-3 h-3" />{{ t('runtimes.sources.signatureVerified') }}</span>
                  <span v-else-if="s.public_key" class="inline-flex items-center gap-1 text-yellow-400"><ShieldAlert class="w-3 h-3" />{{ t('runtimes.sources.signatureNotVerified') }}</span>
                  <span>{{ t('runtimes.sources.every', { n: s.interval_minutes }) }}</span>
                  <button v-if="s.problems?.length" type="button" class="text-yellow-400 hover:text-yellow-300" data-test="runtime-source-problems" @click="!expanded[s.id] && toggleDetail(s)">{{ t('runtimes.sources.problemsCount', { n: s.problems.length }) }}</button>
                </p>
                <p v-if="s.error" class="text-[11px] text-red-400 break-words" data-test="runtime-source-error">{{ s.error }}</p>
                <p v-if="s.is_default && s.status === 'disabled' && !s.url" class="text-[11px] text-gray-400">{{ t('runtimes.sources.defaultNotConfigured') }}</p>
                <p v-if="s.is_default && s.status === 'not_found'" class="text-[11px] text-gray-400" data-test="runtime-source-default-not-found">{{ t('runtimes.sources.defaultNotFound') }}</p>
              </div>
            </div>

            <!-- Enabled -->
            <div class="flex items-center gap-2 w-28">
              <button
                type="button"
                class="relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors"
                :class="[s.is_enabled ? 'bg-brand-500' : 'bg-gray-700', canChange(s) ? 'cursor-pointer' : 'cursor-not-allowed opacity-50']"
                :disabled="!canChange(s) || busy[`${s.id}:toggle`]"
                :title="s.is_enabled ? t('runtimes.sources.actions.disable') : t('runtimes.sources.actions.enable')"
                data-test="runtime-source-toggle"
                @click="setEnabled(s, !s.is_enabled)"
              >
                <span class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-sm transition" :class="s.is_enabled ? 'translate-x-4' : 'translate-x-0'" />
              </button>
              <span class="text-[11px]" :class="s.is_enabled ? 'text-brand-500' : 'text-gray-400'">{{ s.is_enabled ? t('runtimes.list.enabled') : t('runtimes.list.disabled') }}</span>
            </div>

            <!-- Actions -->
            <div v-if="canChange(s)" class="flex items-center gap-1 ml-auto">
              <button
                type="button"
                class="btn-secondary text-xs flex items-center gap-1.5"
                :disabled="busy[`${s.id}:sync`] || !s.is_enabled"
                :title="s.is_enabled ? t('runtimes.sources.actions.syncHint') : t('runtimes.sources.actions.syncDisabled')"
                :data-tour="i === 0 ? 'runtime-sources-sync' : undefined"
                data-test="runtime-source-sync"
                @click="sync(s)"
              >
                <RefreshCw class="w-3.5 h-3.5" :class="busy[`${s.id}:sync`] && 'animate-spin'" />{{ t('runtimes.sources.actions.sync') }}
              </button>
              <button class="icon-btn" :title="s.is_default ? t('runtimes.sources.actions.editDefault') : t('runtimes.sources.actions.edit')" data-test="runtime-source-edit" @click="modalTarget = s"><Pencil class="w-4 h-4" /></button>
              <button v-if="!s.is_default" class="icon-btn hover:text-red-400!" :title="t('runtimes.sources.actions.delete')" :disabled="busy[`${s.id}:delete`]" data-test="runtime-source-delete" @click="remove(s)"><Trash2 class="w-4 h-4" /></button>
            </div>
          </div>

          <RuntimeSourceDetail
            v-if="expanded[s.id]"
            :source="s"
            :detail="details[s.id]"
            :busy="busy"
            :can-toggle="canToggleRuntime"
            @toggle-runtime="setRuntimeEnabled(s.id, $event)"
          />
        </div>
      </div>

      <!-- Built-in runtimes (runtime managers): which runtimes of RunR's catalog the platform offers -->
      <BuiltinRuntimesCard v-if="canManage" class="mt-6" @saved="emit('changed')" />
    </template>

    <RuntimeSourceModal
      v-if="modalTarget !== undefined"
      :source="modalTarget"
      :scope="scope"
      @close="modalTarget = undefined"
      @saved="onModalSaved"
    />
  </div>
</template>

<style scoped>
@reference "../../../assets/main.css";
.icon-btn {
  @apply p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors;
}
</style>

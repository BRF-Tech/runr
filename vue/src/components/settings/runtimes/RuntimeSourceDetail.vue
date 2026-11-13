<script setup>
// The detail of a runtime source (GET /runtime-sources/{id}): what its last read refused (file +
// JSON path), its runtimes — on / off here, with their lifecycle and versions — and the patches it
// carries for them, with its patch feed.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, Loader2 } from '@lucide/vue'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import TermText from '@/components/TermText.vue'
import RuntimeLifecycleBadge from '@/components/settings/runtimes/RuntimeLifecycleBadge.vue'
import RuntimeVersionChip from '@/components/settings/runtimes/RuntimeVersionChip.vue'
import { levelClasses, levelKey } from '@/utils/runtimePatches'

const props = defineProps({
  source:    { type: Object, required: true },
  // { loading, error, data: { source, runtimes, patches, patch_feed } }
  detail:    { type: Object, default: null },
  busy:      { type: Object, default: () => ({}) },
  canToggle: { type: Function, default: () => false },
})
defineEmits(['toggle-runtime'])

const { t } = useI18n()

const data      = computed(() => props.detail?.data ?? null)
const problems  = computed(() => (data.value?.source ?? props.source).problems ?? [])
const runtimes  = computed(() => data.value?.runtimes ?? [])
const patches   = computed(() => data.value?.patches ?? [])
const feed      = computed(() => data.value?.patch_feed ?? null)

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : t('runtimes.sources.never'))
</script>

<template>
  <div class="border-t border-gray-800 px-4 py-4 space-y-5" :data-source-detail="source.id">
    <div v-if="detail?.loading && !data" class="flex items-center gap-2 text-xs text-gray-400"><Loader2 class="w-3.5 h-3.5 animate-spin" />{{ t('common.loading') }}</div>
    <p v-else-if="detail?.error" class="text-xs text-red-400">{{ detail.error }}</p>

    <!-- Problems of the last read -->
    <section v-if="problems.length" data-test="runtime-source-detail-problems">
      <p class="section-title flex items-center gap-2 text-yellow-400!"><AlertTriangle class="w-3.5 h-3.5" />{{ t('runtimes.sources.detail.problems', { n: problems.length }) }}</p>
      <div class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead>
            <tr class="text-left text-gray-400 border-b border-gray-800">
              <th class="py-1.5 pr-3 font-medium">{{ t('runtimes.sources.detail.file') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('runtimes.sources.detail.runtime') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('runtimes.sources.detail.path') }}</th>
              <th class="py-1.5 font-medium">{{ t('runtimes.sources.detail.message') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(p, i) in problems" :key="i" class="border-b border-gray-800/60 align-top">
              <td class="py-1.5 pr-3 font-mono text-gray-300 break-all">{{ p.file || '—' }}</td>
              <td class="py-1.5 pr-3 font-mono text-gray-300">{{ p.runtime || '—' }}</td>
              <td class="py-1.5 pr-3 font-mono text-red-300 whitespace-nowrap">{{ p.path || '/' }}</td>
              <td class="py-1.5 text-gray-300">{{ p.message }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <template v-if="data">
      <!-- Runtimes of the source -->
      <section data-test="runtime-source-detail-runtimes">
        <p class="section-title"><TermText path="runtimes.sources.detail.runtimes" :params="{ n: runtimes.length }" /></p>
        <p v-if="!runtimes.length" class="text-xs text-gray-400">{{ t('runtimes.sources.detail.noRuntimes') }}</p>
        <div v-else class="space-y-1.5">
          <div v-for="r in runtimes" :key="r.id" class="flex flex-wrap items-center gap-3 rounded-lg border border-gray-800 bg-gray-950/40 px-3 py-2" :class="!r.is_enabled && 'opacity-60'" :data-source-runtime="r.id">
            <div class="flex items-center gap-2 min-w-[200px] flex-1">
              <RuntimeIcon :runtime="r.id" :size="18" />
              <span class="text-xs font-semibold text-gray-100">{{ r.name }}</span>
              <span class="font-mono text-[11px] text-gray-400">{{ r.id }}</span>
              <RuntimeLifecycleBadge :runtime="r" />
            </div>
            <div class="flex flex-wrap gap-1 max-w-[320px]">
              <RuntimeVersionChip v-for="v in r.versions" :key="v.version" :version="v" />
            </div>
            <span v-if="r.projects_count !== null" class="font-mono text-[11px] text-gray-400 w-20">{{ t('runtimes.list.projects', { n: r.projects_count }) }}</span>
            <button
              type="button"
              class="relative inline-flex h-5 w-9 shrink-0 rounded-full border-2 border-transparent transition-colors"
              :class="[r.is_enabled ? 'bg-brand-500' : 'bg-gray-700', canToggle(r) ? 'cursor-pointer' : 'cursor-not-allowed opacity-50']"
              :disabled="!canToggle(r) || busy[`runtime:${r.id}`]"
              :title="r.is_enabled ? t('runtimes.actions.disable') : t('runtimes.actions.enable')"
              data-test="runtime-source-runtime-toggle"
              @click="$emit('toggle-runtime', r)"
            >
              <span class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-sm transition" :class="r.is_enabled ? 'translate-x-4' : 'translate-x-0'" />
            </button>
          </div>
        </div>
      </section>

      <!-- Patches the source carries for its runtimes -->
      <section data-test="runtime-source-detail-patches">
        <p class="section-title">{{ t('runtimes.sources.detail.patches', { n: patches.length }) }}</p>
        <p v-if="feed" class="text-[11px] text-gray-400 mb-2">
          {{ t('runtimes.sources.detail.feed', { reference: feed.reference, status: t(`runtimePatches.admin.feed.statuses.${feed.status || 'disabled'}`) }) }}
          <span v-if="feed.fetched_at"> · {{ t('runtimes.sources.detail.feedFetched', { date: fmt(feed.fetched_at) }) }}</span>
          <span v-if="feed.error" class="text-red-400"> · {{ feed.error }}</span>
        </p>
        <p v-if="!patches.length" class="text-xs text-gray-400">{{ t('runtimes.sources.detail.noPatches') }}</p>
        <table v-else class="w-full text-xs">
          <thead>
            <tr class="text-left text-gray-400 border-b border-gray-800">
              <th class="py-1.5 pr-3 font-medium">{{ t('runtimePatches.admin.patches.runtime') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('runtimePatches.admin.patches.tag') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('runtimePatches.admin.patches.level') }}</th>
              <th class="py-1.5 pr-3 font-medium">{{ t('runtimePatches.admin.patches.published') }}</th>
              <th class="py-1.5 font-medium">{{ t('runtimePatches.admin.patches.deadline') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in patches" :key="p.id" class="border-b border-gray-800/60">
              <td class="py-1.5 pr-3 font-mono text-gray-100">{{ p.runtime }} {{ p.version }}</td>
              <td class="py-1.5 pr-3 font-mono text-gray-100">{{ p.tag }}</td>
              <td class="py-1.5 pr-3"><span class="px-2 py-0.5 rounded-md border" :class="levelClasses(p.level)">{{ t(`runtimePatches.levels.${levelKey(p.level)}`) }}</span></td>
              <td class="py-1.5 pr-3 text-gray-400">{{ fmt(p.published_at) }}</td>
              <td class="py-1.5 text-gray-400">{{ p.deadline_at ? fmt(p.deadline_at) : t('runtimePatches.admin.patches.none') }}</td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>

<style scoped>
@reference "../../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-2 border-b border-gray-800/60 pb-1.5;
}
</style>

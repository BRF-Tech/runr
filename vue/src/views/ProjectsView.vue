<script setup>
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { useFallbackPolling } from '@/composables/useFallbackPolling'
import { usePlatformSync } from '@/composables/usePlatformSync'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { rightsizingApi } from '@/api/rightsizing'
import { money } from '@/utils/rightsizing'
import { useTutorialStore } from '@/stores/tutorial'
import { usePermissionsStore } from '@/stores/permissions'
import { useRuntimesStore } from '@/stores/runtimes'
import { useProjectsTutorial } from '@/composables/useTutorial'
import { useProjectsList } from '@/composables/useProjectsList'
import { useLatest } from '@/composables/useLatest'
import { createChangeJournal, insertChange } from '@/utils/changeJournal'
import { runtimeTag, endpointUrl } from '@/utils/projectHelpers'
import AppHeader from '@/components/AppHeader.vue'
import ResourceBars from '@/components/ResourceBars.vue'
import ProjectCard from '@/components/ProjectCard.vue'
import RegionFilterDropdown from '@/components/RegionFilterDropdown.vue'
import RuntimeFilterDropdown from '@/components/RuntimeFilterDropdown.vue'
import ScalingFilterDropdown from '@/components/ScalingFilterDropdown.vue'
import CreateProjectModal from '@/components/CreateProjectModal.vue'
import EditProjectModal from '@/components/EditProjectModal.vue'
import RuntimePatchDialog from '@/components/project/RuntimePatchDialog.vue'
import { moduleRegistry } from '@/modules/index'
import { Gauge, Inbox, LayoutGrid } from 'lucide-vue-next'

const { t, locale } = useI18n()
const route         = useRoute()
const tutorialStore = useTutorialStore()
const perms         = usePermissionsStore()
const runtimesStore = useRuntimesStore()
const { start: startTutorial } = useProjectsTutorial()
const projects      = ref([])
const loading       = ref(true)
const showCreate    = ref(false)
const editTarget    = ref(null)
// Runtime patches of a project (badge on its card)
const patchTarget   = ref(null)

function onPatchesChanged(state) {
  const p = projects.value.find(x => x.id === patchTarget.value?.id)
  if (p) p.runtime_patch = state
}

const {
  filter, searchQuery,
  regionFilter, regionDropOpen, availableRegions,
  runtimeFilter, runtimeDropOpen, availableRuntimes,
  scalingFilter, scalingDropOpen,
  filtered, toggleFilter, closeDropdowns,
  capacityTotal, regionBreakdown, hasReadyRegion,
  regionName, isDemoRegion,
  settingsStore,
} = useProjectsList(projects)

// ── Rightsizing: a link to the open recommendations (metrics.view on some project) ──
const rightsizing = ref(null)

async function loadRightsizing() {
  if (!perms.canAnywhere('metrics.view')) return
  try {
    const { data } = await rightsizingApi.list()
    rightsizing.value = data?.summary ?? null
  } catch {
    rightsizing.value = null   // an API without rightsizing: no link
  }
}

const rightsizingCount = computed(() => Number(rightsizing.value?.count ?? 0))
const rightsizingLabel = computed(() => {
  const s = rightsizing.value
  return s?.monthly_savings > 0 && s.currency
    ? t('rightsizing.linkSaving', { count: s.count, amount: money(s.monthly_savings, s.currency, locale.value) })
    : t('rightsizing.link', { count: rightsizingCount.value })
})

// ── Load ─────────────────────────────────────────────────────────────────────
// The newest list wins. Changes made while a list request is in flight (a project created,
// changed or deleted here, in another tab or by someone else: the broadcasts) are recorded and
// applied to its answer, which may have been read before them (usePlatformSync records the
// broadcasts in the same journal).
const latest = useLatest()
const journal = createChangeJournal()

/** Applies a local change to the list and records it for a list request in flight. */
function change(apply) {
  projects.value = apply(projects.value)
  journal.record(apply)
}

async function load() {
  const current = latest.begin()
  const since = journal.mark()
  loading.value = true
  // Runtime catalog: names and icons of custom runtimes on the cards and in the filter
  runtimesStore.fetch()
  loadRightsizing()
  try {
    const [projectsRes] = await Promise.all([
      projectsApi.list(),
      settingsStore.loaded ? Promise.resolve() : settingsStore.fetch(),
    ])
    if (current()) projects.value = journal.replay(projectsRes.data.data ?? projectsRes.data, since)
  } finally {
    if (current()) loading.value = false
  }
}

async function silentRefresh() {
  if (tutorialStore.tutorialActive) return
  const current = latest.begin()
  const since = journal.mark()
  const [projectsRes] = await Promise.all([
    projectsApi.list(),
    settingsStore.fetch(),
  ])
  if (!current()) return
  projects.value = journal.replay(projectsRes.data.data ?? projectsRes.data, since)
  loading.value = false // it may have overtaken the first load
}

function onCreated(project) {
  showCreate.value = false
  perms.load() // a creator without organization-wide access gets the Admin role on the project
  change(insertChange(project))
}

function onUpdated(updated) {
  change((list) => list.map(p => (p.id === updated.id ? updated : p)))
  editTarget.value = null
}

function saveScroll() {
  sessionStorage.setItem('projects-scroll', String(window.scrollY))
}

// The page may be left while the first load runs: nothing below may start after that (listeners
// that are never removed, the projects tour over another page)
let unmounted = false

onMounted(async () => {
  document.addEventListener('click', closeDropdowns)
  window.addEventListener('beforeunload', saveScroll)
  await load()
  if (unmounted) return
  if (route.hash) {
    nextTick(() => document.querySelector(route.hash)?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
  } else {
    const saved = sessionStorage.getItem('projects-scroll')
    if (saved) {
      nextTick(() => window.scrollTo(0, Number(saved)))
      sessionStorage.removeItem('projects-scroll')
    }
  }
  if (tutorialStore.shouldAutoStart) {
    nextTick(() => startTutorial())
  }
})

onUnmounted(() => {
  unmounted = true
  document.removeEventListener('click', closeDropdowns)
  window.removeEventListener('beforeunload', saveScroll)
})

watch(() => tutorialStore.restartCounter, () => {
  nextTick(() => startTutorial())
})

useFallbackPolling(silentRefresh, 90000)
usePlatformSync({ projects, refetch: silentRefresh, journals: { projects: journal } })
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppHeader />

    <main class="flex-1 w-full px-6 py-8">
      <!-- Top bar -->
      <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <LayoutGrid class="w-5 h-5 text-gray-400" />
          <h1 class="text-xl font-semibold text-gray-100 tracking-wide">{{ t('projects.title') }}</h1>
          <span
            class="ml-1 px-2 py-0.5 rounded-md text-xs font-mono font-medium text-white"
            style="background:rgb(var(--brand-600))"
          >{{ projects.length }}</span>
          <RouterLink
            v-if="rightsizingCount > 0"
            to="/rightsizing"
            class="ml-1 inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-xs text-sky-300 bg-sky-950/40 border border-sky-900/60 hover:text-sky-200"
            data-testid="rightsizing-link"
          ><Gauge class="w-3.5 h-3.5" />{{ rightsizingLabel }}</RouterLink>
        </div>

        <div class="flex items-center gap-3">
          <input
            data-tour="search-input"
            v-model="searchQuery"
            type="text"
            :placeholder="t('projects.searchPlaceholder')"
            class="h-9 bg-gray-900 border border-gray-800 rounded-md px-3 text-sm text-gray-100 placeholder-gray-400 focus:outline-hidden focus:border-gray-600 w-48"
          />
          <div data-tour="filter-tabs" class="h-9 flex items-center gap-1 bg-gray-900 border border-gray-800 rounded-md px-1">
            <button
              v-for="tab in [
                { id: 'all',  label: t('projects.filterAll') },
                { id: 'xaas', label: 'XaaS' },
                { id: 'faas', label: 'FaaS' },
                { id: 'caas', label: 'CaaS' },
              ]"
              :key="tab.id"
              class="px-3 py-1 rounded-md text-xs font-mono transition-colors"
              :class="filter === tab.id ? 'text-white' : 'text-gray-400 hover:text-gray-100'"
              :style="filter === tab.id ? 'background-color:rgb(var(--brand-600))' : ''"
              @click="filter = tab.id; tab.id === 'caas' && (runtimeFilter = [])"
            >{{ tab.label }}</button>
          </div>

          <!-- Filter dropdowns -->
          <div data-tour="filter-dropdowns" class="flex items-center gap-3">
          <RuntimeFilterDropdown
            v-if="filter !== 'caas'"
            :runtimes="availableRuntimes"
            :selected-runtimes="runtimeFilter"
            :open="runtimeDropOpen"
            @update:open="runtimeDropOpen = $event"
            @toggle="toggleFilter(runtimeFilter, $event)"
            @clear="runtimeFilter = []; runtimeDropOpen = false"
          />

          <!-- Scaling filter dropdown -->
          <ScalingFilterDropdown
            :selected-scaling="scalingFilter"
            :open="scalingDropOpen"
            @update:open="scalingDropOpen = $event"
            @toggle="toggleFilter(scalingFilter, $event)"
            @clear="scalingFilter = []; scalingDropOpen = false"
          />

          <!-- Region filter dropdown -->
          <RegionFilterDropdown
            :regions="availableRegions"
            :selected-regions="regionFilter"
            :open="regionDropOpen"
            @update:open="regionDropOpen = $event"
            @toggle="toggleFilter(regionFilter, $event)"
            @clear="regionFilter = []; regionDropOpen = false"
          />
          </div>

          <!-- Module actions next to New project (e.g. the one-click MCP server) -->
          <template v-if="!loading">
            <component
              :is="action" v-for="(action, i) in moduleRegistry.projectsActions" :key="i"
              :has-ready-region="hasReadyRegion" :existing-names="projects.map(p => p.name)" @created="onCreated"
            />
          </template>

          <div v-if="perms.can('projects.create')" data-tour="new-project-btn" class="relative">
            <div v-if="loading" class="h-[38px] w-28 rounded-md bg-gray-800 animate-pulse" />
            <template v-else>
              <button
                :class="hasReadyRegion ? 'btn-primary' : 'btn bg-red-900/60 text-red-400 border border-red-800/50 cursor-not-allowed'"
                :disabled="!hasReadyRegion"
                @click="hasReadyRegion && (showCreate = true)"
              >{{ t('projects.newProject') }}</button>
              <div v-if="!hasReadyRegion" class="absolute right-0 top-full mt-2 z-10">
                <div class="absolute -top-1 right-4 w-2 h-2 bg-red-950 border-l border-t border-red-800/60 rotate-45" />
                <div class="bg-red-950 border border-red-800/60 rounded-md px-3 py-1.5 text-[11px] text-red-400 whitespace-nowrap shadow-lg">
                  {{ t('projects.noReadyRegion') }}
                </div>
              </div>
            </template>
          </div>
        </div>
      </div>

      <!-- Resource bars -->
      <div v-if="!loading && capacityTotal" data-tour="resource-bars">
      <ResourceBars
        :total="capacityTotal"
        :region-breakdown="regionBreakdown"
      />
      </div>

      <!-- Loading skeletons -->
      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div v-for="i in 6" :key="i" class="card h-44 animate-pulse bg-gray-900/50" />
      </div>

      <!-- Empty -->
      <div v-else-if="filtered.length === 0" class="text-center py-24 text-gray-400">
        <Inbox class="w-10 h-10 mx-auto mb-3 text-gray-400" />
        <p class="text-sm">{{ t('projects.empty') }}</p>
      </div>

      <!-- Grid -->
      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <ProjectCard
          v-for="(project, idx) in filtered"
          :key="project.id"
          :data-tour="idx === 0 ? 'project-card' : undefined"
          :project="project"
          :region-name="regionName(project)"
          :is-demo-region="isDemoRegion(project)"
          :endpoint-url="endpointUrl(project)"
          :runtime-tag="runtimeTag(project)"
          :runtime-eol="project.runtime_eol"
          @edit="editTarget = $event"
          @patches="patchTarget = $event"
        />
      </div>
    </main>

    <CreateProjectModal v-if="showCreate" :existing-names="projects.map(p => p.name)" @created="onCreated" @close="showCreate = false" />

    <EditProjectModal
      v-if="editTarget"
      :project="editTarget"
      @updated="onUpdated"
      @close="editTarget = null"
    />

    <RuntimePatchDialog
      v-if="patchTarget"
      :project="patchTarget"
      @changed="onPatchesChanged"
      @close="patchTarget = null"
    />
  </div>
</template>

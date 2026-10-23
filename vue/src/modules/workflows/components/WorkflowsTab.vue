<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Workflow as WorkflowIcon, Play, Pause, AlertTriangle, Clock, Radio, Zap } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { usePermissionsStore } from '@/stores/permissions'
import { workflowsApi } from '../api/workflows'
import WorkflowAppPanel from './WorkflowAppPanel.vue'
import WorkflowRunsTable from './WorkflowRunsTable.vue'
import WorkflowRunDetail from './WorkflowRunDetail.vue'
import StartRunModal from './StartRunModal.vue'
import { triggerKind, triggerLabel } from '../utils/format'

const props = defineProps({
  project: { type: Object, required: true },
  // The environment the project page's switcher selected (docs/workflows.md#environments):
  // its own workflow app, definitions and runs; null: production
  environment: { type: String, default: null },
})

const { t } = useI18n()
const toast  = useToast()
const route  = useRoute()
const router = useRouter()

const workflows   = ref([])
const app         = ref(null)
const loading     = ref(true)
const loadError   = ref(null)
const selected    = ref('')                       // workflow filter of the runs table
const openRunId   = ref(route?.query?.run ?? null) // run detail
const startFor    = ref(null)                     // workflow name of the start modal
const reloadKey   = ref(0)

// docs/permissions.md: starting / cancelling runs and changing definitions are separate permissions
const perms     = usePermissionsStore()
const canRun    = computed(() => perms.can('workflows.run', props.project.id))
const canManage = computed(() => perms.can('workflows.manage', props.project.id))

const isWorkflowProject = computed(() => (app.value?.trigger_type ?? props.project?.config?.trigger_type) === 'workflow')
const names = computed(() => workflows.value.map((w) => w.name))

const TRIGGER_ICONS = { event: Zap, cron: Clock, queue: Radio }

// quiet: refresh in place (no spinner), so the app panel and the error it shows stay mounted
async function load({ quiet = false } = {}) {
  if (!quiet) {
    loading.value = true
    loadError.value = null
  }
  try {
    const { data } = await workflowsApi.list(props.project.id, props.environment)
    workflows.value = data.data ?? []
    app.value       = data.app ?? null
  } catch (e) {
    if (!quiet) loadError.value = e.response?.data?.message ?? t('workflows.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
watch(() => props.project?.id, () => load())
// Another environment: its own definitions and runs, none of the previous one's open
watch(() => props.environment, () => {
  selected.value  = ''
  openRunId.value = null
  startFor.value  = null
  load()
})

watch(openRunId, (id) => {
  if (!router) return
  const query = { ...(route?.query ?? {}) }
  if (id) query.run = id
  else delete query.run
  router.replace({ query, hash: route?.hash }).catch(() => {})
})

function onSynced(payload) {
  if (payload) {
    workflows.value = payload.data ?? workflows.value
    app.value       = payload.app ?? app.value
  } else {
    // the sync failed: the panel shows why; the reload brings the app's sync status
    load({ quiet: true })
  }
}

async function toggleActive(w) {
  try {
    const { data } = await workflowsApi.setActive(props.project.id, w.name, !w.is_active, props.environment)
    workflows.value = workflows.value.map((x) => (x.id === w.id ? { ...x, ...data.data } : x))
    toast.success(data.data.is_active ? t('workflows.list.resumed_ok') : t('workflows.list.paused_ok'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

function onStarted({ run, created }) {
  startFor.value = null
  toast.success(created ? t('workflows.start.started') : t('workflows.start.deduplicated'))
  reloadKey.value++
  openRunId.value = run.id
}

function totalRuns(w) {
  return Object.values(w.run_counts ?? {}).reduce((a, b) => a + b, 0)
}
</script>

<template>
  <div class="space-y-4" data-test="workflows-tab">
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,#8b5cf6,rgb(var(--brand-500)))"></div>
          <span class="text-[15px] font-semibold text-gray-100">{{ t('workflows.title') }}</span>
        </div>
        <p class="text-xs text-gray-400 mt-1.5 ml-[10px]">{{ t('workflows.intro') }}</p>
      </div>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else-if="loadError" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3">
      <p class="text-xs text-red-400">{{ loadError }}</p>
    </div>

    <template v-else>
      <div v-if="!isWorkflowProject" class="flex items-start gap-2.5 bg-yellow-900/20 border border-yellow-800/40 rounded-md px-4 py-3" data-test="not-workflow-project">
        <AlertTriangle class="w-4 h-4 text-yellow-400 mt-0.5 shrink-0" />
        <p class="text-xs text-yellow-300 leading-relaxed">{{ t('workflows.notWorkflowProject') }}</p>
      </div>

      <WorkflowAppPanel
        v-if="app"
        :project-id="project.id"
        :app="app"
        :environment="environment"
        :can-manage="canManage"
        @updated="(a) => (app = a)"
        @synced="onSynced"
      />

      <!-- Run detail -->
      <WorkflowRunDetail
        v-if="openRunId"
        :key="openRunId"
        :project-id="project.id"
        :run-id="openRunId"
        :can-run="canRun"
        @back="openRunId = null"
        @open-run="(id) => (openRunId = id)"
        @changed="reloadKey++"
      />

      <template v-else>
        <!-- Definitions -->
        <div v-if="!workflows.length" class="text-center text-gray-400 py-6 text-sm" data-test="workflows-empty">
          <p>{{ t('workflows.list.empty') }}</p>
          <p class="text-xs mt-1">{{ t('workflows.list.emptyHint') }}</p>
        </div>

        <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3" data-test="workflow-list">
          <div
            v-for="w in workflows" :key="w.id"
            class="bg-gray-900 border rounded-md p-3 space-y-2 transition-colors"
            :class="[selected === w.name ? 'border-sky-700/60' : 'border-gray-800', w.archived ? 'opacity-60' : '']"
            :data-workflow="w.name"
          >
            <div class="flex items-center gap-2">
              <WorkflowIcon class="w-4 h-4 text-violet-300 shrink-0" />
              <button type="button" class="font-mono text-sm text-gray-100 hover:text-sky-300 truncate" @click="selected = selected === w.name ? '' : w.name">{{ w.name }}</button>
              <span v-if="w.archived" class="text-[10px] px-1.5 rounded-sm bg-gray-800 text-gray-400">{{ t('workflows.list.archived') }}</span>
              <span v-else-if="!w.is_active" class="text-[10px] px-1.5 rounded-sm bg-amber-900/40 text-amber-300">{{ t('workflows.list.paused') }}</span>
              <span class="ml-auto text-[11px] text-gray-400">{{ t('workflows.list.runs') }}: {{ totalRuns(w) }}</span>
            </div>
            <p v-if="w.description" class="text-xs text-gray-400">{{ w.description }}</p>
            <div class="flex flex-wrap gap-1.5">
              <span v-if="!w.triggers?.length" class="text-[11px] text-gray-400">{{ t('workflows.list.noTriggers') }}</span>
              <span
                v-for="(tr, i) in w.triggers" :key="i"
                class="inline-flex items-center gap-1 text-[11px] font-mono px-1.5 py-0.5 rounded-sm bg-gray-800 text-gray-300"
              >
                <component :is="TRIGGER_ICONS[triggerKind(tr)]" class="w-3 h-3" />{{ triggerLabel(tr) }}
              </span>
            </div>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-gray-400">
              <span>{{ t('workflows.list.retries', { n: w.retries }) }}</span>
              <span v-if="w.timeout">{{ t('workflows.list.timeout', { t: w.timeout }) }}</span>
              <span v-for="(c, i) in w.concurrency" :key="i">
                {{ c.key ? t('workflows.list.concurrencyKeyed', { n: c.limit, key: c.key }) : t('workflows.list.concurrency', { n: c.limit }) }}
              </span>
            </div>
            <div v-if="!w.archived && (canRun || canManage)" class="flex gap-1.5 pt-1">
              <button v-if="canRun" type="button" class="btn-primary text-xs px-2.5 py-1" :disabled="!w.is_active" :data-test="`start-${w.name}`" @click="startFor = w.name">
                <Play class="w-3 h-3" />{{ t('workflows.list.startRun') }}
              </button>
              <button v-if="canManage" type="button" class="btn-secondary text-xs px-2.5 py-1" :data-test="`toggle-${w.name}`" @click="toggleActive(w)">
                <Pause v-if="w.is_active" class="w-3 h-3" /><Play v-else class="w-3 h-3" />
                {{ w.is_active ? t('workflows.list.pause') : t('workflows.list.resume') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Runs -->
        <section>
          <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2">{{ t('workflows.runs.title') }}</p>
          <WorkflowRunsTable
            v-model:workflow="selected"
            :project-id="project.id"
            :workflows="names"
            :reload-key="reloadKey"
            :environment="environment"
            @open="(id) => (openRunId = id)"
          />
        </section>
      </template>
    </template>

    <StartRunModal
      v-if="startFor"
      :project-id="project.id"
      :workflow="startFor"
      :environment="environment"
      @started="onStarted"
      @close="startFor = null"
    />
  </div>
</template>

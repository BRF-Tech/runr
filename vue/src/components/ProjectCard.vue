<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useTheme } from '@/composables/useTheme'
import { describeCron } from '@/utils/cron'
import { moduleRegistry } from '@/modules/index'
import TypeBadge from '@/components/TypeBadge.vue'
import McpServerBadge from '@/components/McpServerBadge.vue'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import RuntimePatchBadge from '@/components/project/RuntimePatchBadge.vue'
import { runtimeLabel } from '@/utils/runtimes'
import { isLegacyServiceType } from '@/utils/projectHelpers'
import { patchBadge } from '@/utils/runtimePatches'
import { isGlobalProject } from '@/utils/globalProjects'
import { lifecycleClasses, projectEndedBy, projectLifecycleNotice } from '@/utils/runtimeLifecycle'
import { usePermissionsStore } from '@/stores/permissions'
import { ExternalLink, Globe, Earth, Clock, Zap, Cpu, MemoryStick, Timer, ArrowUpDown, User2 } from 'lucide-vue-next'

const { t } = useI18n()
const router = useRouter()
const { current: theme } = useTheme()

const props = defineProps({
  project:     { type: Object, required: true },
  regionName:    { type: String, default: null },
  isDemoRegion:  { type: Boolean, default: false },
  endpointUrl: { type: String, default: null },
  runtimeTag:  { type: String, default: null },
  runtimeEol:  { type: String, default: null },
})

const isEol = computed(() => props.runtimeEol && new Date(props.runtimeEol) <= new Date())
const eolWeeksLeft = computed(() => {
  if (!props.runtimeEol || isEol.value) return null
  const diffDays = (new Date(props.runtimeEol) - new Date()) / (1000 * 60 * 60 * 24)
  return Math.ceil(diffDays / 7)
})
const isSunsetting = computed(() => eolWeeksLeft.value !== null && eolWeeksLeft.value <= 13)
// runtimeEol is the earlier of the version's end of life and sunset (docs/runtimes.md#lifecycle)
const endedLabel = computed(() => projectEndedBy(props.project) === 'sunset' ? t('runtimes.lifecycle.versionStates.sunset') : t('projects.eol'))
// Deprecated, or the runtime left its source (not ended yet, not in its last 13 weeks)
const lifecycleMark = computed(() => {
  const state = props.project.runtime_lifecycle?.state
  if ((state !== 'deprecated' && state !== 'orphaned') || isEol.value || isSunsetting.value) return null
  return { state, label: t(`runtimes.lifecycle.states.${state}`), classes: lifecycleClasses(state), title: projectLifecycleNotice(props.project, t, runtimeLabel)?.title ?? '' }
})
// Global project (docs/global-projects.md): served from several regions on its global host name
const isGlobal = computed(() => isGlobalProject(props.project))
const globalRegionNames = computed(() => (props.project.global?.regions ?? []).map(r => r.region_name).join(', '))

// Runtime patch state (docs/runtimes.md#runtime-patches): a badge next to the EOL ones
const hasPatchBadge = computed(() => !!patchBadge(props.project.runtime_patch))
const patchOverdue = computed(() => props.project.runtime_patch?.state === 'overdue')

// The edit pencil: the same permissions as the project page's Edit button (ProjectHeader). A
// read-only role does not get it; the API would refuse the change (403)
const perms = usePermissionsStore()
const canEdit = computed(() =>
  !isLegacyServiceType(props.project.service_type)
  && (perms.can('projects.update', props.project.id) || perms.can('env.edit', props.project.id)))

const cardClass = computed(() => {
  if (isEol.value || patchOverdue.value) return 'border-red-900/50 bg-red-950/20'
  if (isSunsetting.value) return 'border-yellow-900/50 bg-yellow-950/20'
  return ''
})

// patches: the runtime patch badge was clicked (the projects page opens the dialog)
defineEmits(['edit', 'patches'])
</script>

<template>
  <div
    :id="`p-${project.slug}`"
    class="card hover:border-gray-700 transition-colors cursor-pointer group flex flex-col gap-2.5 min-h-[200px]"
    :class="cardClass"
    @click="router.push({ path: `/projects/${project.id}`, state: { fromSlug: project.slug } })"
  >
    <!-- Header: the badges go under the name and wrap when a narrow card has no room beside it -->
    <div class="flex flex-wrap items-start justify-between gap-x-2 gap-y-1.5">
      <div class="min-w-0 max-w-full">
        <h2 class="font-medium text-gray-100 truncate group-hover:text-brand-400 transition-colors">
          {{ project.name }}
        </h2>
      </div>
      <div class="flex flex-wrap items-center gap-1.5">
        <TypeBadge :type="project.service_type" />
        <McpServerBadge :server="project.mcp_server" />
        <span
          v-if="isGlobal"
          class="inline-flex items-center gap-1 text-xs font-medium rounded-md border px-2.5 py-1 bg-brand-900/30 border-brand-800/50 text-brand-300"
          :title="globalRegionNames"
          data-test="project-card-global"
        ><Earth class="w-3 h-3" />{{ t('globalProjects.badge') }}</span>
        <span
          v-if="regionName"
          class="inline-flex items-center text-xs font-mono font-medium rounded-md border px-2.5 py-1"
          :class="theme === 'light'
            ? 'text-gray-950 bg-gray-200 border border-gray-300'
            : 'text-white bg-white/10 border border-white/20'"
        >{{ regionName }}</span>
        <span
          v-if="project.config?.scale_to_zero != null"
          class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md"
          :class="project.config.scale_to_zero
            ? 'bg-transparent border border-brand-500/60 text-brand-400'
            : 'bg-brand-900/30 border border-brand-800/50 text-brand-300'"
        ><ArrowUpDown class="w-3 h-3" />{{ project.config.scale_to_zero ? t('projects.scalingS2z') : t('projects.scalingCustomLabel', { min: project.config.min_scale, max: project.config.max_scale }) }}</span>
        <button
          v-if="canEdit"
          class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-gray-100 transition-all p-0.5"
          :title="t('project.edit')"
          data-test="project-card-edit"
          @click.stop="$emit('edit', project)"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
            <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Description -->
    <p class="text-sm line-clamp-2 flex-1 leading-relaxed" :class="project.description ? 'text-gray-100' : 'text-gray-400 italic'">
      {{ project.description || t('projects.noDescription') }}
    </p>

    <!-- Endpoint / Schedule / Queue -->
    <div v-if="endpointUrl || project.config?.schedule || project.config?.queue_stream" class="text-xs">
      <div v-if="endpointUrl" class="flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md shrink-0 border bg-brand-900/30 border-brand-800/50 text-brand-300">
          <Globe class="w-3 h-3" />HTTP
        </span>
        <a
          :href="endpointUrl"
          target="_blank"
          rel="noopener"
          class="font-mono text-brand-500/80 hover:text-brand-400 truncate flex items-center gap-1 transition-colors"
          @click.stop
        >
          <span class="truncate">{{ endpointUrl }}</span>
          <ExternalLink class="w-3 h-3 shrink-0" />
        </a>
      </div>
      <div v-else-if="project.config?.schedule" class="flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md border bg-transparent border-brand-500/60 text-brand-400 shrink-0">
          <Clock class="w-3 h-3" />CRON
        </span>
        <span class="font-mono text-gray-400 truncate">{{ project.config.schedule }}</span>
        <span class="text-gray-400 shrink-0">·</span>
        <span class="text-gray-400 italic truncate">{{ describeCron(project.config.schedule, t) }}</span>
      </div>
      <div v-else-if="project.config?.queue_stream" class="flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md shrink-0 border bg-gray-800/60 border-gray-700 text-brand-300">
          <Zap class="w-3 h-3" />STREAM
        </span>
        <span class="font-mono text-gray-400 truncate">{{ project.config.queue_stream }}{{ moduleRegistry.queueStreamSuffixFn?.() || '' }}</span>
      </div>
    </div>

    <!-- Runtime + EOL tags -->
    <div v-if="project.runtime || runtimeTag || isEol || isSunsetting || hasPatchBadge || lifecycleMark" class="flex items-center gap-1.5">
      <span
        v-if="project.runtime"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 font-mono text-xs text-gray-100"
      ><RuntimeIcon :runtime="project.runtime" :size="14" />{{ runtimeLabel(project.runtime) }}{{ project.runtime_version ? ' ' + project.runtime_version : '' }}</span>
      <span
        v-if="runtimeTag && project.service_type === 'caas'"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 font-mono text-xs text-gray-100"
      ><RuntimeIcon runtime="docker" :size="14" />{{ runtimeTag }}</span>
      <span v-if="isEol || isSunsetting || hasPatchBadge || lifecycleMark" class="ml-auto flex items-center gap-1.5">
        <RuntimePatchBadge :state="project.runtime_patch" size="sm" @open="$emit('patches', project)" />
        <span
          v-if="isEol"
          class="text-[11px] font-medium px-2 py-0.5 rounded-md bg-red-900/40 border border-red-800/50 text-red-400 leading-none"
        >{{ endedLabel }}</span>
        <span
          v-else-if="isSunsetting"
          class="text-[11px] font-medium px-2 py-0.5 rounded-md bg-yellow-900/40 border border-yellow-800/50 text-yellow-400 leading-none"
          :title="`${t('projects.sunsetting')}: ${runtimeEol}`"
        >{{ t('projects.eolWeeks', { weeks: eolWeeksLeft }) }}</span>
        <span
          v-if="lifecycleMark"
          class="text-[11px] font-medium px-2 py-0.5 rounded-md border leading-none"
          :class="lifecycleMark.classes"
          :title="lifecycleMark.title"
          :data-lifecycle="lifecycleMark.state"
          data-test="project-card-lifecycle"
        >{{ lifecycleMark.label }}</span>
      </span>
    </div>

    <!-- Resource chips + creator + version -->
    <div class="flex items-center justify-between gap-2 text-xs text-gray-400">
      <div class="flex items-center gap-2 min-w-0">
        <div
          v-if="project.config?.cpu || project.config?.memory_mb || project.config?.timeout_seconds"
          class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 font-mono text-gray-100"
        >
          <span v-if="project.config?.cpu" class="flex items-center gap-0.5"><Cpu class="w-3 h-3 text-gray-400" />{{ project.config.cpu }}</span>
          <span v-if="project.config?.cpu && project.config?.memory_mb" class="text-gray-400">·</span>
          <span v-if="project.config?.memory_mb" class="flex items-center gap-0.5"><MemoryStick class="w-3 h-3 text-gray-400" />{{ project.config.memory_mb }} MB</span>
          <span v-if="project.config?.timeout_seconds" class="text-gray-400">·</span>
          <span v-if="project.config?.timeout_seconds" class="flex items-center gap-0.5"><Timer class="w-3 h-3 text-gray-400" />{{ project.config.timeout_seconds }}s</span>
        </div>
        <span v-if="project.created_by?.name" :title="project.created_by.name" class="inline-flex min-w-0 items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 text-gray-300">
          <User2 class="w-3 h-3 shrink-0 text-gray-400" /><span class="truncate">{{ project.created_by.name }}</span>
        </span>
      </div>
      <!-- Version badges -->
      <div v-if="project.latest_deployment || project.latest_version" class="flex shrink-0 items-center gap-1 font-mono">
        <template v-if="project.latest_deployment?.version">
          <span
            v-if="['pending','running'].includes(project.latest_deployment.status)"
            class="text-yellow-400/90"
            :title="project.latest_deployment.status"
          >○ {{ project.latest_deployment.version.label }}</span>
          <span
            v-else-if="project.latest_deployment.status === 'active'"
            class="text-emerald-500/80"
            :title="t('projects.version.active')"
          >● {{ project.latest_deployment.version.label }}</span>
          <span
            v-else-if="project.latest_deployment.status === 'failed'"
            class="text-red-500/80"
            :title="t('projects.version.failed')"
          >✕ {{ project.latest_deployment.version.label }}</span>
          <span v-else class="text-gray-400">{{ project.latest_deployment.version.label }}</span>
          <template v-if="project.latest_version && project.latest_deployment.version.id !== project.latest_version.id">
            <span class="text-gray-400">→</span>
            <span class="text-gray-400" :title="t('projects.version.latestNotDeployed')">{{ project.latest_version.label }}</span>
          </template>
        </template>
        <span v-else-if="project.latest_version" class="text-gray-400">
          {{ project.latest_version.label }}
        </span>
      </div>
    </div>
  </div>
</template>

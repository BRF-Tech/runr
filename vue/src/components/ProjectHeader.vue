<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { describeCron } from '@/utils/cron'
import { moduleRegistry } from '@/modules/index'
import TypeBadge from '@/components/TypeBadge.vue'
import McpServerBadge from '@/components/McpServerBadge.vue'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import RuntimePatchBadge from '@/components/project/RuntimePatchBadge.vue'
import { runtimeLabel } from '@/utils/runtimes'
import { isLegacyServiceType } from '@/utils/projectHelpers'
import { isGlobalProject } from '@/utils/globalProjects'
import { usePermissionsStore } from '@/stores/permissions'
import { projectStrategy } from '@/utils/deployStrategy'
import { MapPin, Cpu, MemoryStick, Timer, Pencil, Play, Clock, Zap, Globe, Earth, Copy, Check, ArrowUpDown, UserRound, OctagonPause, Bird, Columns2 } from 'lucide-vue-next'

const { t } = useI18n()
const router = useRouter()

const props = defineProps({
  project:     { type: Object, required: true },
  regionLabel: { type: String, default: null },
  endpointUrl: { type: String, default: null },
  tabs:        { type: Array, default: () => [] },
  activeTab:   { type: String, default: null },
  deploying:    { type: Boolean, default: false },
  hasVersions:  { type: Boolean, default: true },
  isRuntimeEol: { type: Boolean, default: false },
})

const isEol = computed(() => props.project.runtime_eol && new Date(props.project.runtime_eol) <= new Date())
const eolWeeksLeft = computed(() => {
  if (!props.project.runtime_eol || isEol.value) return null
  const diffDays = (new Date(props.project.runtime_eol) - new Date()) / (1000 * 60 * 60 * 24)
  return Math.ceil(diffDays / 7)
})
const isSunsetting = computed(() => eolWeeksLeft.value !== null && eolWeeksLeft.value <= 13)
// Global project: one host name, several regions (docs/global-projects.md)
const isGlobal = computed(() => isGlobalProject(props.project))
const globalRegionCount = computed(() => props.project.global?.regions?.length ?? 0)
const globalRegionNames = computed(() => (props.project.global?.regions ?? []).map(r => r.region_name).join(', '))
const isBlueprintMode = computed(() =>
  ['aaas', 'agentflow'].includes(props.project?.service_type) &&
  props.project?.config?.config_mode === 'blueprint'
)
// Former AI types: shown and deletable, not editable, clonable or deployable
const isLegacy = computed(() => isLegacyServiceType(props.project?.service_type))
// Blue-green / canary deploys (direct, the default, shows nothing)
const deployStrategy = computed(() => projectStrategy(props.project))

// Actions the user's role allows on this project (docs/permissions.md)
const perms = usePermissionsStore()
const canUpdate = computed(() => perms.can('projects.update', props.project.id) || perms.can('env.edit', props.project.id))
const canClone  = computed(() => perms.can('projects.create'))
const canDelete = computed(() => perms.can('projects.delete', props.project.id))
const canDeploy = computed(() => perms.can('projects.deploy', props.project.id))
// Stop / resume (projects.stop) and the owner — the payer of the project's bills. A platform
// administrator also stops a project its organization stopped: the stop becomes the platform's.
const isStopped = computed(() => !!props.project.stopped)

// Timed warmer (config.scale_schedule): what the scheduler last kept warm (project.warm_scale)
const warmerOn = computed(() => Array.isArray(props.project.config?.scale_schedule) && props.project.config.scale_schedule.length > 0)
const warmerBadge = computed(() => {
  const applied = props.project.warm_scale?.applied
  return applied === null || applied === undefined
    ? t('warmer.badge')
    : t('warmer.badgeApplied', { count: applied }, Number(applied))
})
const canStop   = computed(() => perms.can('projects.stop'))
const canTakeOver = computed(() => isStopped.value && props.project.stopped.level !== 'platform' && perms.platformAdmin)
const currentUserId = (() => {
  try { return String(JSON.parse(localStorage.getItem('user') || 'null')?.id ?? '') } catch { return '' }
})()
const isOwner = computed(() => !!props.project.owner_id && String(props.project.owner_id) === currentUserId)
const canTransfer = computed(() => isOwner.value || perms.can('projects.manage_access', props.project.id))

// patches: the runtime patch badge was clicked (the project page opens the dialog)
defineEmits(['edit', 'clone', 'delete', 'deploy', 'stop', 'transfer-owner', 'patches', 'update:activeTab'])

const urlCopied = ref(false)

async function copyUrl() {
  if (!props.endpointUrl) return
  await navigator.clipboard.writeText(props.endpointUrl)
  urlCopied.value = true
  setTimeout(() => { urlCopied.value = false }, 2000)
}
</script>

<template>
  <div class="border-b border-gray-800 bg-gray-950 sticky top-0 z-10">
    <div class="px-6 pt-3 pb-0">

      <!-- Breadcrumb -->
      <div class="flex items-center gap-2 mb-4">
        <button
          class="flex items-center gap-1.5 text-sm text-gray-400 hover:text-brand-500 transition-colors font-medium"
          @click="router.push('/projects')"
        >{{ t('project.backToProjects') }}</button>
      </div>

      <!-- Title row -->
      <div data-tour="project-header" class="flex items-start justify-between gap-4 mb-3">
        <div class="flex items-center gap-3 min-w-0">
          <div class="w-0.5 h-9 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <div class="min-w-0">
            <h1 class="text-lg font-semibold text-gray-100 leading-tight mb-1.5">{{ project.name }}</h1>
            <div class="flex flex-wrap items-center gap-1.5">
              <TypeBadge :type="project.service_type" />
              <McpServerBadge :server="project.mcp_server" />
              <span v-if="project.runtime && !isBlueprintMode" class="inline-flex items-center gap-1.5 text-xs font-mono font-medium px-2.5 py-1 rounded-md border" style="background:rgb(var(--brand-500)/0.12);border-color:rgb(var(--brand-500)/0.35);color:rgb(var(--brand-500))"><RuntimeIcon :runtime="project.runtime" :size="14" />{{ runtimeLabel(project.runtime) }}{{ project.runtime_version ? ' ' + project.runtime_version : '' }}</span>
              <span v-if="isBlueprintMode" class="inline-flex items-center gap-1.5 text-xs font-mono font-medium px-2.5 py-1 rounded-md border" style="background:rgb(139 92 246/0.12);border-color:rgb(139 92 246/0.35);color:#8b5cf6">Blueprint</span>
              <span v-if="project.service_type === 'caas' && project.config?.image" class="inline-flex items-center gap-1.5 text-xs font-mono font-medium px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 text-gray-100"><RuntimeIcon runtime="docker" :size="14" />{{ project.config.image }}</span>
              <span v-if="regionLabel" class="inline-flex items-center gap-1 text-xs font-mono font-medium px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 text-gray-400"><MapPin class="w-3 h-3" />{{ regionLabel }}</span>
              <!-- Global project (docs/global-projects.md): the primary above, every region on hover -->
              <span v-if="isGlobal" class="inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-md border bg-sky-900/30 border-sky-800/50 text-sky-300" :title="globalRegionNames" data-test="project-global-badge"><Earth class="w-3 h-3" />{{ t('globalProjects.badge') }}<template v-if="globalRegionCount"> · {{ t('globalProjects.badgeRegions', { n: globalRegionCount }) }}</template></span>
              <span
                v-if="project.config?.scale_to_zero != null"
                class="inline-flex items-center gap-1 text-xs font-mono font-medium px-2.5 py-1 rounded-md border"
                :class="project.config.scale_to_zero
                  ? 'bg-emerald-900/30 border-emerald-800/50 text-emerald-400'
                  : 'bg-blue-900/30 border-blue-800/50 text-blue-400'"
              >
                <ArrowUpDown class="w-3 h-3" />
                {{ project.config.scale_to_zero ? t('projects.scalingS2z') : t('projects.scalingCustomLabel', { min: project.config.min_scale, max: project.config.max_scale }) }}
              </span>
              <span
                v-if="warmerOn"
                class="inline-flex items-center gap-1 text-xs font-mono font-medium px-2.5 py-1 rounded-md border bg-amber-900/20 border-amber-800/50 text-amber-400"
                :title="project.warm_scale?.applied_at || ''"
                data-testid="warmer-badge"
              ><Clock class="w-3 h-3" />{{ warmerBadge }}</span>
              <span
                v-if="deployStrategy !== 'direct'"
                class="inline-flex items-center gap-1 text-xs font-mono font-medium px-2.5 py-1 rounded-md border"
                :class="deployStrategy === 'canary' ? 'bg-amber-900/30 border-amber-800/50 text-amber-400' : 'bg-sky-900/30 border-sky-800/50 text-sky-400'"
                :title="t('deployStrategy.badgeHint')"
                data-test="project-strategy-badge"
              >
                <component :is="deployStrategy === 'canary' ? Bird : Columns2" class="w-3 h-3" />{{ t(`deployStrategy.names.${deployStrategy}`) }}
              </span>
              <span v-if="isStopped" class="inline-flex items-center gap-1 text-xs font-medium px-2.5 py-1 rounded-md bg-red-900/40 border border-red-800/50 text-red-400" data-test="project-stopped-badge"><OctagonPause class="w-3 h-3" />{{ t('projectLifecycle.stoppedBadge') }}</span>
              <span v-if="isEol" class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded-md bg-red-900/40 border border-red-800/50 text-red-400">{{ t('projects.eol') }}</span>
              <span v-else-if="isSunsetting" class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded-md bg-yellow-900/40 border border-yellow-800/50 text-yellow-400" :title="`${t('projects.sunsetting')}: ${project.runtime_eol}`">{{ t('projects.eolWeeks', { weeks: eolWeeksLeft }) }}</span>
              <RuntimePatchBadge :state="project.runtime_patch" @open="$emit('patches')" />
            </div>
          </div>
        </div>

        <div data-tour="action-buttons" class="flex items-center gap-2 shrink-0 pt-1">
          <button v-if="!isLegacy && canUpdate" class="btn-secondary flex items-center gap-1.5" @click="$emit('edit')"><Pencil class="w-3.5 h-3.5" />{{ t('project.edit') }}</button>
          <button v-if="!isLegacy && canClone" class="btn-secondary" @click="$emit('clone')">{{ t('project.clone') }}</button>
          <button v-if="canStop && (!isStopped || canTakeOver) && !isLegacy" class="btn-secondary text-red-400 hover:text-red-300 border-red-900/50 hover:border-red-700" data-test="project-stop" @click="$emit('stop')">{{ canTakeOver ? t('projectLifecycle.takeOver') : t('projectLifecycle.stop') }}</button>
          <button v-if="canDelete" class="btn-secondary text-red-400 hover:text-red-300 border-red-900/50 hover:border-red-700" @click="$emit('delete')">{{ t('project.delete') }}</button>
          <button
            v-if="!isLegacy && canDeploy"
            class="btn-primary flex items-center gap-1.5"
            :disabled="deploying || !hasVersions || isRuntimeEol || isStopped"
            :title="isStopped ? t('projectLifecycle.deployBlocked') : isRuntimeEol ? t('project.eolBlocked') : undefined"
            @click="$emit('deploy')"
          ><Play class="w-3.5 h-3.5" />{{ t('project.deploy') }}</button>
        </div>
      </div>

      <!-- Meta row -->
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 mb-3 ml-[14px]">
        <span v-if="project.owner || project.owner_id" class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 text-gray-300" data-test="project-owner">
          <UserRound class="w-3 h-3 text-gray-400" />{{ t('projectLifecycle.owner') }}: {{ project.owner?.name ?? project.owner?.username ?? t('projectLifecycle.ownerUnknown') }}
          <button v-if="canTransfer && !isLegacy" type="button" class="text-brand-400 hover:text-brand-300" data-test="project-owner-transfer" @click="$emit('transfer-owner')">{{ t('projectLifecycle.transfer') }}</button>
        </span>
        <div v-if="endpointUrl" class="flex items-center gap-1.5 min-w-0">
          <span class="inline-flex items-center gap-1 text-xs font-mono font-medium px-2.5 py-1 rounded-md shrink-0" style="background:rgb(16 185 129/0.12);border:1px solid rgb(16 185 129/0.3);color:#10b981"><Globe class="w-3 h-3" />HTTP</span>
          <a :href="endpointUrl" target="_blank" rel="noopener" class="text-xs font-mono text-brand-400 hover:text-brand-300 truncate transition-colors">{{ endpointUrl }}</a>
          <button @click="copyUrl" class="text-gray-400 hover:text-gray-100 transition-colors shrink-0">
            <Check v-if="urlCopied" class="w-3.5 h-3.5 text-green-400" />
            <Copy v-else class="w-3.5 h-3.5" />
          </button>
        </div>
        <div v-if="project.config?.schedule" class="flex items-center gap-1.5 shrink-0">
          <span class="inline-flex items-center gap-1 text-xs font-mono font-medium px-2.5 py-1 rounded-md bg-amber-900/30 border border-amber-800/50 text-amber-400"><Clock class="w-3 h-3" />CRON</span>
          <span class="text-xs font-mono text-gray-100">{{ project.config.schedule }}</span>
          <span class="text-xs text-gray-400 italic">· {{ describeCron(project.config.schedule, t) }}</span>
        </div>
        <div v-if="project.config?.queue_stream" class="flex items-center gap-1.5 shrink-0">
          <span class="inline-flex items-center gap-1 text-xs font-mono font-medium px-2.5 py-1 rounded-md" style="background:rgb(var(--brand-500)/0.12);border:1px solid rgb(var(--brand-500)/0.3);color:rgb(var(--brand-500))"><Zap class="w-3 h-3" />STREAM</span>
          <span class="text-xs font-mono text-gray-100">{{ project.config.queue_stream }}{{ moduleRegistry.queueStreamSuffixFn?.() || '' }}</span>
        </div>
        <span v-if="project.config?.cpu || project.config?.memory_mb || project.config?.timeout_seconds"
          class="inline-flex items-center gap-2 text-xs font-mono font-medium px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 text-gray-100"
        >
          <span v-if="project.config?.cpu" class="flex items-center gap-0.5"><Cpu class="w-3 h-3 text-gray-400" />{{ project.config.cpu }}</span>
          <span v-if="project.config?.cpu && project.config?.memory_mb" class="text-gray-400">·</span>
          <span v-if="project.config?.memory_mb" class="flex items-center gap-0.5"><MemoryStick class="w-3 h-3 text-gray-400" />{{ project.config.memory_mb }} MB</span>
          <span v-if="project.config?.timeout_seconds" class="text-gray-400">·</span>
          <span v-if="project.config?.timeout_seconds" class="flex items-center gap-0.5"><Timer class="w-3 h-3 text-gray-400" />{{ project.config.timeout_seconds }}s</span>
        </span>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 -mb-px">
        <button
          v-for="tab in tabs" :key="tab.id"
          class="tab-line px-5 py-2.5 text-[15px]"
          :class="activeTab === tab.id ? 'is-active' : ''"
          :data-tour="tab.id === 'deployments' ? 'deployments-tab' : tab.id === 'metrics' ? 'metrics-tab' : undefined"
          @click="$emit('update:activeTab', tab.id)"
        >{{ tab.label }}</button>
      </div>

    </div>
  </div>
</template>

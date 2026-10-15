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
import { runtimeLabel } from '@/utils/runtimes'
import { isLegacyServiceType } from '@/utils/projectHelpers'
import { ExternalLink, Globe, Clock, Zap, Cpu, MemoryStick, Timer, ArrowUpDown, User2 } from 'lucide-vue-next'

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

const cardClass = computed(() => {
  if (isEol.value) return 'border-red-900/50 bg-red-950/20'
  if (isSunsetting.value) return 'border-yellow-900/50 bg-yellow-950/20'
  return ''
})

defineEmits(['edit'])
</script>

<template>
  <div
    :id="`p-${project.slug}`"
    class="card hover:border-gray-700 transition-colors cursor-pointer group flex flex-col gap-2.5 min-h-[200px]"
    :class="cardClass"
    @click="router.push({ path: `/projects/${project.id}`, state: { fromSlug: project.slug } })"
  >
    <!-- Header -->
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h2 class="font-medium text-gray-100 truncate group-hover:text-brand-400 transition-colors">
          {{ project.name }}
        </h2>
      </div>
      <div class="flex items-center gap-1.5 shrink-0">
        <TypeBadge :type="project.service_type" />
        <McpServerBadge :server="project.mcp_server" />
        <span
          v-if="regionName"
          class="inline-flex items-center text-xs font-mono font-medium rounded-md border px-2.5 py-1"
          :class="theme === 'light'
            ? 'text-white bg-brand-600 border border-brand-700'
            : 'text-white bg-white/10 border border-white/20'"
        >{{ regionName }}</span>
        <span
          v-if="project.config?.scale_to_zero != null"
          class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md"
          :class="project.config.scale_to_zero
            ? 'bg-emerald-900/30 border border-emerald-800/50 text-emerald-400'
            : 'bg-blue-900/30 border border-blue-800/50 text-blue-400'"
        ><ArrowUpDown class="w-3 h-3" />{{ project.config.scale_to_zero ? t('projects.scalingS2z') : t('projects.scalingCustomLabel', { min: project.config.min_scale, max: project.config.max_scale }) }}</span>
        <button
          v-if="!isLegacyServiceType(project.service_type)"
          class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-gray-100 transition-all p-0.5"
          :title="t('project.edit')"
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
        <span class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md shrink-0" style="background:rgb(16 185 129/0.12);border:1px solid rgb(16 185 129/0.3);color:#10b981">
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
        <span class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md bg-amber-900/30 border border-amber-800/50 text-amber-400 shrink-0">
          <Clock class="w-3 h-3" />CRON
        </span>
        <span class="font-mono text-gray-400 truncate">{{ project.config.schedule }}</span>
        <span class="text-gray-400 shrink-0">·</span>
        <span class="text-gray-400 italic truncate">{{ describeCron(project.config.schedule, t) }}</span>
      </div>
      <div v-else-if="project.config?.queue_stream" class="flex items-center gap-1.5">
        <span class="inline-flex items-center gap-1 font-mono text-xs px-2.5 py-1 rounded-md shrink-0" style="background:rgb(var(--brand-500)/0.12);border:1px solid rgb(var(--brand-500)/0.3);color:rgb(var(--brand-500))">
          <Zap class="w-3 h-3" />STREAM
        </span>
        <span class="font-mono text-gray-400 truncate">{{ project.config.queue_stream }}{{ moduleRegistry.queueStreamSuffixFn?.() || '' }}</span>
      </div>
    </div>

    <!-- Runtime + EOL tags -->
    <div v-if="project.runtime || runtimeTag || isEol || isSunsetting" class="flex items-center gap-1.5">
      <span
        v-if="project.runtime"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 font-mono text-xs text-gray-100"
      ><RuntimeIcon :runtime="project.runtime" :size="14" />{{ runtimeLabel(project.runtime) }}{{ project.runtime_version ? ' ' + project.runtime_version : '' }}</span>
      <span
        v-if="runtimeTag && project.service_type === 'caas'"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 font-mono text-xs text-gray-100"
      ><RuntimeIcon runtime="docker" :size="14" />{{ runtimeTag }}</span>
      <span
        v-if="isEol"
        class="ml-auto text-[11px] font-medium px-2 py-0.5 rounded-md bg-red-900/40 border border-red-800/50 text-red-400 leading-none"
      >{{ t('projects.eol') }}</span>
      <span
        v-else-if="isSunsetting"
        class="ml-auto text-[11px] font-medium px-2 py-0.5 rounded-md bg-yellow-900/40 border border-yellow-800/50 text-yellow-400 leading-none"
        :title="runtimeEol"
      >{{ t('projects.eolWeeks', { weeks: eolWeeksLeft }) }}</span>
    </div>

    <!-- Resource chips + creator + version -->
    <div class="flex items-center justify-between text-xs text-gray-400">
      <div class="flex items-center gap-2 min-w-0">
        <div
          v-if="project.config?.cpu || project.config?.memory_mb || project.config?.timeout_seconds"
          class="inline-flex items-center gap-2 px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 font-mono text-gray-100"
        >
          <span v-if="project.config?.cpu" class="flex items-center gap-0.5"><Cpu class="w-3 h-3 text-gray-400" />{{ project.config.cpu }}</span>
          <span v-if="project.config?.cpu && project.config?.memory_mb" class="text-gray-400">·</span>
          <span v-if="project.config?.memory_mb" class="flex items-center gap-0.5"><MemoryStick class="w-3 h-3 text-gray-400" />{{ project.config.memory_mb }} MB</span>
          <span v-if="project.config?.timeout_seconds" class="text-gray-400">·</span>
          <span v-if="project.config?.timeout_seconds" class="flex items-center gap-0.5"><Timer class="w-3 h-3 text-gray-400" />{{ project.config.timeout_seconds }}s</span>
        </div>
        <span v-if="project.created_by?.name" class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 text-gray-300 truncate">
          <User2 class="w-3 h-3 shrink-0 text-gray-400" />{{ project.created_by.name }}
        </span>
      </div>
      <!-- Version badges -->
      <div v-if="project.latest_deployment || project.latest_version" class="flex items-center gap-1 font-mono">
        <template v-if="project.latest_deployment?.version">
          <span
            v-if="['pending','running'].includes(project.latest_deployment.status)"
            class="text-amber-400/90"
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

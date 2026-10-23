<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusBadge from '@/components/StatusBadge.vue'
import { usePermissionsStore } from '@/stores/permissions'
import TermText from '@/components/TermText.vue'
import { isLegacyServiceType } from '@/utils/projectHelpers'
import { asRollout, projectStrategy, rolloutRoles, keepsPrevious, holdsTraffic, clockTime } from '@/utils/deployStrategy'
import { SlidersHorizontal } from 'lucide-vue-next'

const { t, locale } = useI18n()

const props = defineProps({
  deployments:    { type: Array, default: () => [] },
  project:        { type: Object, default: null },
  // The newest rollout (blue-green / canary) of the environment shown: the roles of the deployments in it
  currentRollout: { type: Object, default: null },
  // The URL of the environment the deployments run in (docs/environments.md); default: the project's
  endpoint:    { type: String, default: null },
  // The deploy strategy of the environment shown (EnvironmentResource deploy_strategy); default: the project's
  strategy:    { type: String, default: null },
})

defineEmits(['view-logs', 'delete', 'configure-strategy', 'rollback'])

// Deleting a deployment: projects.deploy on the project (docs/permissions.md)
const canDeploy = computed(() => usePermissionsStore().can('projects.deploy', props.project?.id))

function fmt(timestamp) {
  if (!timestamp) return '—'
  return new Date(timestamp).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}

const triggerType = computed(() => props.project?.config?.trigger_type ?? null)
const endpointUrl = computed(() => props.endpoint ?? props.project?.endpoint_url ?? null)

// ── Deploy strategy + rollout chips ─────────────────────────────────────────
const strategy     = computed(() => projectStrategy(props.strategy ? { ...props.project, deploy_strategy: props.strategy } : props.project))
const showStrategy = computed(() => !!props.project && !isLegacyServiceType(props.project.service_type))

const CHIP = {
  candidate: 'bg-sky-900/30 border-sky-800/60 text-sky-300',
  waiting:   'bg-violet-900/30 border-violet-800/60 text-violet-300',
  stable:    'bg-emerald-900/30 border-emerald-800/60 text-emerald-300',
  previous:  'bg-gray-800 border-gray-700 text-gray-300',
  promoted:  'bg-emerald-900/30 border-emerald-800/60 text-emerald-300',
  aborted:   'bg-gray-800 border-gray-700 text-gray-400',
  rolled_back: 'bg-orange-900/30 border-orange-800/60 text-orange-300',
  failed:    'bg-red-900/30 border-red-800/60 text-red-300',
}

// rollout_role comes from the API and the deployment.rollout events; an older answer has none
function roleOf(d) {
  if (d.rollout_role !== undefined) return d.rollout_role
  const current = asRollout(props.currentRollout)
  const own = asRollout(d.rollout)
  return (current && rolloutRoles(current)[d.id]) ?? (own && rolloutRoles(own)[d.id]) ?? null
}

// The rollout a deployment serves next to (stable) or stays warm for (previous)
function servedRollout(d) {
  const current = asRollout(props.currentRollout)
  if (current?.stable_deployment_id === d.id) return current
  return asRollout(props.deployments.find(x => x.rollout?.stable_deployment_id === d.id && holdsTraffic(x.rollout))?.rollout)
}

const pct = (n) => t('rollout.percent', { n: Math.round(Number(n) || 0) })

function rolloutChip(d) {
  const role = roleOf(d)
  if (role === 'stable' || role === 'previous') {
    const r = servedRollout(d)
    if (role === 'stable') {
      return { text: r ? t('rollout.chip.current', { percent: pct(r.traffic?.stable) }) : t('rollout.chip.currentPlain'), cls: CHIP.stable }
    }
    return r && keepsPrevious(r)
      ? { text: t('rollout.chip.previousUntil', { time: clockTime(r.keep_until, locale.value) }), cls: CHIP.previous }
      : { text: t('rollout.chip.previous'), cls: CHIP.previous }
  }
  const r = asRollout(d.rollout)
  if (!r) return null
  const name = t(`rollout.chipStrategy.${r.strategy}`, r.strategy)
  if (role === 'candidate') {
    if (r.status === 'promoted') return { text: t('rollout.chip.newPromoted'), cls: CHIP.promoted }
    if (r.status === 'waiting_promotion') return { text: t('rollout.chip.newWaiting'), cls: CHIP.waiting }
    return { text: t('rollout.chip.new', { strategy: name, percent: pct(r.traffic?.candidate) }), cls: CHIP.candidate }
  }
  // a rollout of this deployment that is over: how it ended
  if (!holdsTraffic(r) && CHIP[r.status]) {
    const strategy = t(`deployStrategy.names.${r.strategy}`, r.strategy)
    return { text: t('rollout.chip.finished', { strategy, status: t(`rollout.statuses.${r.status}`, r.status) }), cls: CHIP[r.status] }
  }
  return null
}

const chips = computed(() => Object.fromEntries(props.deployments.map(d => [d.id, rolloutChip(d)])))

// Roll back (#626): to any earlier deployment of a version, not to the one that runs now
const liveId = computed(() => props.deployments.find(d => d.status === 'active')?.id ?? null)
function canRollBack(d) {
  return canDeploy.value && !!d.version && d.id !== liveId.value && !['pending', 'running'].includes(d.status)
}
</script>

<template>
  <div class="px-6">
    <!-- Section header -->
    <div class="flex items-center gap-2 mb-5">
      <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <span class="text-[15px] font-semibold text-gray-100">{{ t('project.tabs.deployments') }}</span>
      <span v-if="deployments.length" class="text-xs font-mono font-medium px-2.5 py-1 rounded-md ml-1" style="background:rgb(var(--brand-500)/0.1);color:rgb(var(--brand-500))">{{ deployments.length }}</span>
      <!-- Deploy strategy of the environment shown (direct / blue-green / canary): its settings -->
      <button
        v-if="showStrategy"
        type="button"
        class="ml-auto inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-md border border-gray-700/60 bg-gray-800/40 text-gray-300 hover:text-gray-100 hover:border-gray-500 transition-colors"
        :title="t('deployStrategy.openHint')"
        data-test="deploy-strategy-open"
        @click="$emit('configure-strategy')"
      >
        <SlidersHorizontal class="w-3.5 h-3.5" />{{ t('deployStrategy.label') }}:
        <span class="font-medium" :class="strategy === 'direct' ? 'text-gray-100' : 'text-brand-400'">{{ t(`deployStrategy.names.${strategy}`) }}</span>
      </button>
    </div>

    <!-- Enterprise table card -->
    <div data-tour="deployment-table" class="rounded-md border border-gray-800 overflow-hidden">
      <table class="w-full text-sm">
        <colgroup>
          <col style="width:11%" />
          <col style="width:9%" />
          <col style="width:18%" />
          <col style="width:26%" />
          <col style="width:13%" />
          <col style="width:15%" />
          <col style="width:8%" />
        </colgroup>
        <thead>
          <tr style="background:rgb(var(--gray-800)/0.5)">
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-100 uppercase tracking-wider">{{ t('project.table.status') }}</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-100 uppercase tracking-wider">{{ t('project.table.version') }}</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-100 uppercase tracking-wider">{{ t('project.table.k8sJob') }}</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-100 uppercase tracking-wider"><TermText path="project.table.endpoint" /></th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-100 uppercase tracking-wider">{{ t('project.table.deployer') }}</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-100 uppercase tracking-wider">{{ t('project.table.date') }}</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(d, i) in deployments"
            :key="d.id"
            class="group border-t border-gray-800/60 hover:bg-gray-800/30 transition-colors cursor-pointer"
            :title="t('project.logs.viewLogs')"
            @click="$emit('view-logs', d)"
          >
            <!-- Status -->
            <td class="px-4 py-4">
              <div class="flex flex-col gap-1">
                <StatusBadge :status="d.status" />
                <span
                  v-if="chips[d.id]"
                  class="inline-flex w-fit max-w-full text-[10px] font-mono px-1.5 py-0.5 rounded-sm border leading-tight"
                  :class="chips[d.id].cls"
                  data-test="rollout-chip"
                >{{ chips[d.id].text }}</span>
                <span
                  v-if="d.config_source === 'restored'"
                  class="self-start text-[10px] px-1.5 py-0.5 rounded-sm bg-sky-900/40 text-sky-300 border border-sky-800/50"
                  :title="t('environments.rollback.restoredHint')"
                  data-testid="deployment-restored"
                >{{ t('environments.rollback.restored') }}</span>
                <p v-if="d.knative_message && d.status !== 'active'" class="text-[11px] text-gray-400 truncate max-w-40" :title="d.knative_message">
                  {{ d.knative_message }}
                </p>
              </div>
            </td>

            <!-- Version -->
            <td class="px-4 py-4 font-mono text-base font-semibold" style="color:rgb(var(--brand-500))">
              {{ d.version?.label ?? '—' }}
            </td>

            <!-- K8s Job -->
            <td class="px-4 py-4 font-mono text-xs text-gray-400 max-w-48">
              <span class="truncate block">{{ d.k8s_job_name ?? '—' }}</span>
            </td>

            <!-- Endpoint -->
            <td class="px-4 py-4">
              <a
                v-if="triggerType === 'http' && endpointUrl"
                :href="endpointUrl" target="_blank" rel="noopener noreferrer"
                class="text-[11px] font-mono text-brand-400 hover:text-brand-300 hover:underline leading-tight block truncate"
                :title="endpointUrl" @click.stop
              >{{ endpointUrl }}</a>
              <span v-else class="text-[11px] font-mono text-gray-400">—</span>
            </td>

            <!-- Deployer -->
            <td class="px-4 py-4 text-sm text-gray-400">{{ d.deployed_by ?? '—' }}</td>

            <!-- Date -->
            <td class="px-4 py-4 text-sm text-gray-400">{{ fmt(d.created_at) }}</td>

            <!-- Actions -->
            <td class="px-4 py-4">
              <div class="flex items-center gap-3 justify-end" @click.stop>
                <button
                  class="text-gray-400 hover:text-gray-100 transition-colors opacity-0 group-hover:opacity-100"
                  :title="t('project.logs.viewLogs')"
                  :data-tour="i === 0 ? 'deployment-log-btn' : undefined"
                  @click="$emit('view-logs', d)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="4 17 10 11 4 5" /><line x1="12" y1="19" x2="20" y2="19" />
                  </svg>
                </button>
                <button
                  v-if="canRollBack(d)"
                  class="text-gray-400 hover:text-gray-100 transition-colors opacity-0 group-hover:opacity-100"
                  :title="t('environments.rollback.action')"
                  data-testid="deployment-rollback"
                  @click="$emit('rollback', d)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="1 4 1 10 7 10" /><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10" />
                  </svg>
                </button>
                <button
                  v-if="canDeploy"
                  class="text-gray-400 hover:text-red-400 transition-colors opacity-0 group-hover:opacity-100"
                  :title="t('project.deleteDeployment.title')"
                  :data-tour="i === 0 ? 'deployment-delete-btn' : undefined"
                  @click="$emit('delete', d)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="3 6 5 6 21 6" />
                    <path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" />
                    <path d="M10 11v6M14 11v6M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2" />
                  </svg>
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="!deployments.length" class="text-center text-gray-400 text-sm py-16">
        {{ t('project.noDeployments') }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import StatusBadge from '@/components/StatusBadge.vue'
import { usePermissionsStore } from '@/stores/permissions'
import TermText from '@/components/TermText.vue'

const { t, locale } = useI18n()

const props = defineProps({
  deployments: { type: Array, default: () => [] },
  project:     { type: Object, default: null },
})

defineEmits(['view-logs', 'delete'])

// Deleting a deployment: projects.deploy on the project (docs/permissions.md)
const canDeploy = computed(() => usePermissionsStore().can('projects.deploy', props.project?.id))

function fmt(timestamp) {
  if (!timestamp) return '—'
  return new Date(timestamp).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}

const triggerType = computed(() => props.project?.config?.trigger_type ?? null)
const endpointUrl = computed(() => props.project?.endpoint_url ?? null)
</script>

<template>
  <div class="px-6">
    <!-- Section header -->
    <div class="flex items-center gap-2 mb-5">
      <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <span class="text-[15px] font-semibold text-gray-100">{{ t('project.tabs.deployments') }}</span>
      <span v-if="deployments.length" class="text-xs font-mono font-medium px-2.5 py-1 rounded-md ml-1" style="background:rgb(var(--brand-500)/0.1);color:rgb(var(--brand-500))">{{ deployments.length }}</span>
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

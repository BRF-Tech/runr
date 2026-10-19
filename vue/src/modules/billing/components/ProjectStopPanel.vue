<script setup>
// Stop and resume projects (projects.stop, a core feature billing operators use): a stopped
// project's workloads stop serving and it cannot be deployed until it is resumed; the reason is
// shown to its members. Unpaid bills never stop anything by themselves.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Play, Search } from 'lucide-vue-next'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import ProjectStopModal from '@/components/project/ProjectStopModal.vue'
import { apiMessage } from '../utils/billing'

const { t } = useI18n()
const toast = useToast()
const perms = usePermissionsStore()

const projects = ref([])
const loading = ref(false)
const error = ref(null)
const query = ref('')
const onlyStopped = ref(false)
const stopping = ref(null)
const busy = ref(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = await projectsApi.list()
    projects.value = data?.data ?? data ?? []
  } catch (e) {
    error.value = apiMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

const visible = computed(() => {
  const q = query.value.trim().toLowerCase()
  return projects.value
    .filter(p => !onlyStopped.value || p.stopped)
    .filter(p => !q || (p.name ?? '').toLowerCase().includes(q) || (p.owner?.name ?? '').toLowerCase().includes(q))
})

// A platform stop is lifted by a platform administrator only (the API answers 403 otherwise)
function canResume(project) {
  return project.stopped?.level !== 'platform' || perms.platformAdmin
}

function replace(updated) {
  if (!updated?.id) return
  projects.value = projects.value.map(p => (p.id === updated.id ? { ...p, ...updated } : p))
}

function onStopped(updated) {
  replace(updated)
  stopping.value = null
  toast.success(t('projectLifecycle.stopped'))
}

async function resume(project) {
  busy.value = project.id
  try {
    const { data } = await projectsApi.resume(project.id)
    replace(data?.data ?? { ...project, stopped: null })
    if (data?.redeploy_error) toast.error(t('projectLifecycle.resumedNotDeployed', { reason: data.redeploy_error }))
    else toast.success(t('projectLifecycle.resumed'))
  } catch (e) {
    toast.error(apiMessage(e, t('common.error')))
  } finally {
    busy.value = null
  }
}

defineExpose({ load, resume, query, onlyStopped })
</script>

<template>
  <div class="space-y-4" data-test="billing-projects">
    <p class="text-xs text-gray-400 max-w-3xl">{{ t('billing.projects.hint') }}</p>
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative">
        <Search class="w-3.5 h-3.5 text-gray-500 absolute left-2.5 top-1/2 -translate-y-1/2" />
        <input v-model="query" class="input h-8 text-xs pl-8 w-64" :placeholder="t('billing.projects.search')" data-test="projects-search" />
      </div>
      <label class="flex items-center gap-2 text-xs text-gray-300">
        <input v-model="onlyStopped" type="checkbox" data-test="projects-only-stopped" />{{ t('billing.projects.onlyStopped') }}
      </label>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error" class="text-sm text-red-400">{{ error }}</p>
    <p v-else-if="!visible.length" class="text-sm text-gray-400 py-6 text-center" data-test="projects-empty">{{ t('billing.projects.empty') }}</p>
    <div v-else class="overflow-x-auto rounded-xl border border-gray-800">
      <table class="w-full text-xs">
        <thead>
          <tr class="text-left text-[10px] text-gray-500 uppercase tracking-wider bg-gray-900/60">
            <th class="px-3 py-2 font-medium">{{ t('billing.projects.project') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('projectLifecycle.owner') }}</th>
            <th class="px-3 py-2 font-medium">{{ t('billing.projects.state') }}</th>
            <th class="px-3 py-2" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in visible" :key="p.id" class="border-t border-gray-800 text-gray-300 align-top" :data-test="`stop-project-${p.id}`">
            <td class="px-3 py-2 text-gray-100">{{ p.name }}</td>
            <td class="px-3 py-2">{{ p.owner?.name ?? t('projectLifecycle.ownerUnknown') }}</td>
            <td class="px-3 py-2">
              <span v-if="p.stopped" class="text-red-400" :data-test="`stop-project-level-${p.id}`">{{ p.stopped.level === 'platform' ? t('projectLifecycle.stoppedByPlatform') : t('projectLifecycle.stoppedByOrganization') }}</span>
              <span v-else class="text-emerald-400">{{ t('billing.projects.running') }}</span>
              <div v-if="p.stopped?.reason" class="text-[10px] text-gray-500 mt-0.5">{{ p.stopped.reason }}</div>
            </td>
            <td class="px-3 py-2 text-right">
              <button v-if="p.stopped && canResume(p)" type="button" class="btn-secondary text-xs" :disabled="busy === p.id" :data-test="`stop-project-resume-${p.id}`" @click="resume(p)">
                <Play class="w-3.5 h-3.5" />{{ t('projectLifecycle.resume') }}
              </button>
              <button v-else type="button" class="btn-secondary text-xs text-red-400" :data-test="`stop-project-stop-${p.id}`" @click="stopping = p">{{ t('projectLifecycle.stop') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ProjectStopModal v-if="stopping" :project="stopping" @stopped="onStopped" @close="stopping = null" />
  </div>
</template>

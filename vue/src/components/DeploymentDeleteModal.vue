<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import StatusBadge from '@/components/StatusBadge.vue'

const { t } = useI18n()

const props = defineProps({
  project:    { type: Object, required: true },
  deployment: { type: Object, required: true },
})

const emit = defineEmits(['deleted', 'close'])

const deleting = ref(false)
const error    = ref(null)

const isLive = ['active', 'pending', 'running', 'shutting_down'].includes(props.deployment.status)

async function confirm() {
  deleting.value = true
  error.value    = null
  try {
    await projectsApi.deleteDeployment(props.project.id, props.deployment.id)
    emit('deleted', props.deployment.id)
  } catch {
    error.value    = t('common.error')
    deleting.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-[2px]">
    <div class="bg-gray-900 border border-gray-800 rounded-xl shadow-2xl w-full max-w-md mx-4">

      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('project.deleteDeployment.title') }}</h3>
        <button class="text-gray-400 hover:text-gray-100 transition-colors text-sm" @click="emit('close')">✕</button>
      </div>

      <!-- Body -->
      <div class="px-6 py-5 space-y-4">

        <!-- Deployment info card -->
        <div class="flex items-center gap-3 bg-gray-800/60 rounded-md px-4 py-3">
          <StatusBadge :status="deployment.status" />
          <div class="min-w-0">
            <p class="text-xs font-mono text-gray-100 truncate">{{ deployment.k8s_job_name ?? deployment.id }}</p>
            <p v-if="deployment.version?.label" class="text-[11px] font-mono mt-0.5" style="color:rgb(var(--brand-500))">
              {{ deployment.version.label }}
            </p>
          </div>
        </div>

        <!-- Main message -->
        <p class="text-sm text-gray-100">{{ t('project.deleteDeployment.message') }}</p>

        <!-- Active warning -->
        <div v-if="isLive" class="flex items-start gap-2.5 bg-orange-900/20 border border-orange-800/40 rounded-md px-4 py-3">
          <svg class="w-4 h-4 text-orange-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
          </svg>
          <p class="text-xs text-orange-300 leading-relaxed">{{ t('project.deleteDeployment.warnActive') }}</p>
        </div>

        <!-- Error -->
        <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-800">
        <button
          class="text-sm text-gray-400 hover:text-gray-100 transition-colors px-4 py-2"
          :disabled="deleting"
          @click="emit('close')"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          class="text-sm font-medium px-4 py-2 rounded-md bg-red-600 hover:bg-red-500 disabled:opacity-50 disabled:cursor-not-allowed text-white transition-colors"
          :disabled="deleting"
          @click="confirm"
        >
          {{ deleting ? t('project.deleteDeployment.deleting') : t('project.deleteDeployment.confirm') }}
        </button>
      </div>

    </div>
  </div>
</template>

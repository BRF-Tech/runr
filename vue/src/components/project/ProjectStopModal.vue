<script setup>
// Stop a project (POST /projects/{id}/stop, projects.stop): its workloads stop serving and it
// cannot be deployed until it is resumed. The reason is required, shown to the project's members
// and recorded in the audit log. Opened by a platform administrator on a project its organization
// stopped, it takes the stop over: the stop becomes the platform's, with the new reason.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from 'lucide-vue-next'
import { projectsApi } from '@/api/projects'

const props = defineProps({
  project: { type: Object, required: true },
})
const emit = defineEmits(['stopped', 'close'])
const { t } = useI18n()

const reason = ref('')
const saving = ref(false)
const error = ref(null)

const valid = computed(() => reason.value.trim().length >= 3)
const takeOver = computed(() => !!props.project.stopped && props.project.stopped.level !== 'platform')

async function submit() {
  if (!valid.value) {
    error.value = t('projectLifecycle.reasonRequired')
    return
  }
  saving.value = true
  error.value = null
  try {
    const { data } = await projectsApi.stop(props.project.id, reason.value.trim())
    emit('stopped', data?.data ?? data)
  } catch (e) {
    const body = e.response?.data
    error.value = (body?.errors ? Object.values(body.errors).flat()[0] : null) || body?.message || t('common.error')
  } finally {
    saving.value = false
  }
}

defineExpose({ reason, submit, takeOver })
</script>

<template>
  <div class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 60" data-test="project-stop-modal">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <form class="relative card w-full max-w-lg space-y-4" @submit.prevent="submit">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-100" data-test="project-stop-title">{{ takeOver ? t('projectLifecycle.takeOverTitle', { name: project.name }) : t('projectLifecycle.stopTitle', { name: project.name }) }}</h2>
        <button type="button" class="text-gray-400 hover:text-gray-100" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>
      <p class="text-sm text-gray-400" data-test="project-stop-hint">{{ takeOver ? t('projectLifecycle.takeOverHint', { reason: project.stopped.reason ?? '' }) : t('projectLifecycle.stopHint') }}</p>
      <div>
        <label class="label">{{ t('projectLifecycle.reason') }}</label>
        <textarea v-model="reason" rows="3" maxlength="1000" class="input" :placeholder="t('projectLifecycle.reasonPlaceholder')" data-test="project-stop-reason" />
      </div>
      <p v-if="error" class="text-sm text-red-400" data-test="project-stop-error">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary bg-red-600 hover:bg-red-500" :disabled="saving || !valid" data-test="project-stop-submit">
          {{ saving ? t('common.saving') : takeOver ? t('projectLifecycle.takeOver') : t('projectLifecycle.stop') }}
        </button>
      </div>
    </form>
  </div>
</template>

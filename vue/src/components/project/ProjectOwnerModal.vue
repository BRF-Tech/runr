<script setup>
// Transfer a project to another owner (PUT /projects/{id}/owner): the owner pays the project's
// bills (docs/billing.md). Allowed for the current owner and holders of projects.manage_access;
// the new owner must be a member who can see the project (the API checks).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from 'lucide-vue-next'
import { projectsApi } from '@/api/projects'
import { rbacApi } from '@/api/rbac'

const props = defineProps({
  project: { type: Object, required: true },
})
const emit = defineEmits(['transferred', 'close'])
const { t } = useI18n()

const members = ref([])
const membersLoaded = ref(false)
const userId = ref('')
const saving = ref(false)
const error = ref(null)

// Members list (members.view); without it the new owner's user id is typed in
onMounted(async () => {
  try {
    const { data } = await rbacApi.members()
    members.value = (data?.members ?? data?.data ?? []).filter(m => String(m.id) !== String(props.project.owner_id ?? ''))
  } catch {
    members.value = []
  } finally {
    membersLoaded.value = true
  }
})

const valid = computed(() => String(userId.value).trim() !== '')

async function submit() {
  if (!valid.value) return
  saving.value = true
  error.value = null
  try {
    const { data } = await projectsApi.transferOwner(props.project.id, String(userId.value).trim())
    emit('transferred', data?.data ?? data)
  } catch (e) {
    const body = e.response?.data
    error.value = (body?.errors ? Object.values(body.errors).flat()[0] : null) || body?.message || t('common.error')
  } finally {
    saving.value = false
  }
}

defineExpose({ userId, submit })
</script>

<template>
  <div class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 60" data-test="project-owner-modal">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <form class="relative card w-full max-w-lg space-y-4" @submit.prevent="submit">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold text-gray-100">{{ t('projectLifecycle.transferTitle', { name: project.name }) }}</h2>
        <button type="button" class="text-gray-400 hover:text-gray-100" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>
      <p class="text-sm text-gray-400">{{ t('projectLifecycle.transferHint', { owner: project.owner?.name ?? t('projectLifecycle.ownerUnknown') }) }}</p>
      <div v-if="membersLoaded && members.length">
        <label class="label">{{ t('projectLifecycle.newOwner') }}</label>
        <select v-model="userId" class="input" data-test="project-owner-select">
          <option value="" disabled>{{ t('projectLifecycle.selectMember') }}</option>
          <option v-for="m in members" :key="m.id" :value="String(m.id)">{{ m.name || m.username }}{{ m.email ? ` (${m.email})` : '' }}</option>
        </select>
      </div>
      <div v-else-if="membersLoaded">
        <label class="label">{{ t('projectLifecycle.userId') }}</label>
        <input v-model="userId" class="input font-mono" data-test="project-owner-user-id" />
      </div>
      <p v-if="error" class="text-sm text-red-400" data-test="project-owner-error">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary" :disabled="saving || !valid" data-test="project-owner-submit">
          {{ saving ? t('common.saving') : t('projectLifecycle.transfer') }}
        </button>
      </div>
    </form>
  </div>
</template>

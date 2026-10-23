<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2, User2, Users } from 'lucide-vue-next'
import { rbacApi } from '@/api/rbac'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useRbacLabels } from '@/composables/useRbac'
import RoleSelect from './RoleSelect.vue'

/** Project "Access" tab: roles for users and groups on this project only. */
const props = defineProps({
  projectId: { type: String, required: true },
})

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const { roleLabel } = useRbacLabels()

const grants    = ref([])
const canManage = ref(false)
const roles     = ref([])
const members   = ref([])
const groups    = ref([])
const loading   = ref(true)

const form   = ref({ subject_type: 'user', subject_id: '', role: 'developer' })
const saving = ref(false)
const error  = ref(null)

const subjects = computed(() => form.value.subject_type === 'user'
  ? members.value.map(m => ({ id: String(m.id), label: `${m.name} — ${m.email}` }))
  : groups.value.map(g => ({ id: g.id, label: g.name })))

function message(e) {
  return e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

async function load() {
  loading.value = true
  try {
    const { data } = await rbacApi.projectAccess(props.projectId)
    grants.value    = data.grants ?? []
    canManage.value = data.can_manage
    if (canManage.value && !roles.value.length) {
      const [r, m, g] = await Promise.all([rbacApi.roles(), rbacApi.members(), rbacApi.groups()])
      roles.value   = (r.data.roles ?? []).filter(x => x.key !== 'owner')
      members.value = m.data.members ?? []
      groups.value  = g.data.groups ?? []
    }
  } finally {
    loading.value = false
  }
}

// The list after a change: its failure (e.g. 403 after revoking one's own access) is not an
// error of the change, which is already saved
async function reload() {
  try { await load() } catch { /* the list keeps what it showed */ }
}

async function grant() {
  saving.value = true
  error.value  = null
  try {
    await rbacApi.grantProjectAccess(props.projectId, form.value)
    toast.success(t('rbac.granted'))
    form.value = { ...form.value, subject_id: '' }
    await reload()
  } catch (e) {
    error.value = message(e)
  } finally {
    saving.value = false
  }
}

async function changeRole(g, role) {
  try {
    await rbacApi.grantProjectAccess(props.projectId, { subject_type: g.subject_type, subject_id: g.subject_id, role })
    toast.success(t('rbac.roleUpdated'))
    await reload()
  } catch (e) {
    toast.error(message(e))
  }
}

async function revoke(g) {
  const ok = await confirm({ title: t('rbac.revoke'), message: t('rbac.revokeConfirm', { name: g.subject_name || g.subject_id }), destructive: true })
  if (!ok) return
  try {
    await rbacApi.revokeProjectAccess(props.projectId, g.id)
    await reload()
  } catch (e) {
    toast.error(message(e))
  }
}

onMounted(load)
</script>

<template>
  <div class="max-w-4xl space-y-4" data-testid="project-access">
    <div>
      <h3 class="text-sm font-semibold text-gray-100">{{ t('rbac.accessTitle') }}</h3>
      <p class="text-xs text-gray-400 mt-0.5">{{ t('rbac.accessDescription') }}</p>
    </div>

    <!-- Grant -->
    <div v-if="canManage" class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3 space-y-3">
      <div class="grid grid-cols-[8rem_1fr_12rem_auto] gap-2 items-end">
        <div>
          <label class="label">{{ t('rbac.subject') }}</label>
          <select v-model="form.subject_type" class="input text-sm" @change="form.subject_id = ''">
            <option value="user">{{ t('rbac.subjectUser') }}</option>
            <option value="group">{{ t('rbac.subjectGroup') }}</option>
          </select>
        </div>
        <div>
          <label class="label">&nbsp;</label>
          <select v-model="form.subject_id" class="input text-sm" data-testid="access-subject">
            <option value="" disabled>{{ t('rbac.selectMembers') }}</option>
            <option v-for="s in subjects" :key="s.id" :value="s.id">{{ s.label }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('rbac.role') }}</label>
          <RoleSelect v-model="form.role" :roles="roles" data-testid="access-role" />
        </div>
        <button class="btn-primary text-xs flex items-center gap-1.5 h-9" :disabled="saving || !form.subject_id || !form.role" data-testid="grant-access" @click="grant">
          <Plus class="w-3.5 h-3.5" />{{ t('rbac.grantAccess') }}
        </button>
      </div>
      <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
    </div>

    <p v-if="loading" class="text-xs text-gray-400 py-4 text-center">{{ t('common.loading') }}</p>
    <p v-else-if="!grants.length" class="text-xs text-gray-400 italic">{{ t('rbac.noGrants') }}</p>

    <div v-else class="space-y-1.5">
      <div v-for="g in grants" :key="g.id" class="flex items-center gap-3 rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2 text-xs" :data-grant="g.subject_name">
        <component :is="g.subject_type === 'group' ? Users : User2" class="w-4 h-4 text-gray-400 shrink-0" />
        <span class="font-medium text-gray-100">{{ g.subject_name || g.subject_id }}</span>
        <span v-if="g.subject_email" class="text-gray-400">{{ g.subject_email }}</span>
        <span class="flex-1" />
        <RoleSelect v-if="canManage && g.grantable" class="w-44" size="xs" :model-value="g.role" :roles="roles"
          @update:model-value="role => changeRole(g, role)" />
        <span v-else class="text-gray-300">{{ roleLabel(g.role, roles) }}</span>
        <button v-if="canManage && g.grantable" class="text-gray-400 hover:text-red-400" :title="t('rbac.revoke')" @click="revoke(g)">
          <Trash2 class="w-3.5 h-3.5" />
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Pencil, Trash2, Users, X, ChevronDown, ChevronRight } from 'lucide-vue-next'
import { rbacApi } from '@/api/rbac'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useRbacLabels } from '@/composables/useRbac'
import RoleSelect from './RoleSelect.vue'

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const perms = usePermissionsStore()
const { roleLabel } = useRbacLabels()

const groups   = ref([])
const roles    = ref([])
const members  = ref([])
const loading  = ref(true)
const openId   = ref(null)
const detail   = ref(null)

const editing = ref(null) // null | 'new' | group id
const form    = ref({ name: '', description: '', role: null })
const saving  = ref(false)
const error   = ref(null)
const toAdd   = ref([])

const canManage     = computed(() => perms.can('groups.manage'))
const canAssignRole = computed(() => canManage.value && perms.can('roles.manage'))
const addable       = computed(() => {
  const inGroup = new Set((detail.value?.members || []).map(m => String(m.id)))
  return members.value.filter(m => !inGroup.has(String(m.id)))
})

function message(e) {
  return e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

async function load() {
  loading.value = true
  try {
    const [g, r, m] = await Promise.all([rbacApi.groups(), rbacApi.roles(), rbacApi.members()])
    groups.value  = g.data.groups ?? []
    roles.value   = r.data.roles ?? []
    members.value = m.data.members ?? []
  } finally {
    loading.value = false
  }
}

async function toggleOpen(group) {
  if (openId.value === group.id) {
    openId.value = null
    detail.value = null
    return
  }
  openId.value = group.id
  toAdd.value  = []
  detail.value = (await rbacApi.group(group.id)).data.group
}

function openNew() {
  editing.value = 'new'
  form.value = { name: '', description: '', role: null }
  error.value = null
}

function openEdit(group) {
  editing.value = group.id
  form.value = { name: group.name, description: group.description || '', role: group.role }
  error.value = null
}

async function save() {
  saving.value = true
  error.value  = null
  try {
    const payload = { name: form.value.name, description: form.value.description }
    if (canAssignRole.value) payload.role = form.value.role
    if (editing.value === 'new') await rbacApi.createGroup(payload)
    else await rbacApi.updateGroup(editing.value, payload)
    toast.success(t('rbac.saved'))
    editing.value = null
    await load()
  } catch (e) {
    error.value = message(e)
  } finally {
    saving.value = false
  }
}

async function remove(group) {
  const ok = await confirm({ title: t('rbac.deleteGroup'), message: t('rbac.deleteGroupConfirm', { name: group.name }), destructive: true })
  if (!ok) return
  try {
    await rbacApi.deleteGroup(group.id)
    if (openId.value === group.id) { openId.value = null; detail.value = null }
    await load()
  } catch (e) {
    toast.error(message(e))
  }
}

async function addMembers() {
  if (!toAdd.value.length) return
  try {
    detail.value = (await rbacApi.addGroupMembers(openId.value, toAdd.value)).data.group
    toAdd.value = []
    await load()
  } catch (e) {
    toast.error(message(e))
  }
}

async function removeMember(member) {
  try {
    detail.value = (await rbacApi.removeGroupMember(openId.value, member.id)).data.group
    await load()
  } catch (e) {
    toast.error(message(e))
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-5" data-testid="groups-manager">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('rbac.groupsTitle') }}</h3>
          <p class="text-xs text-gray-400 mt-0.5">{{ t('rbac.groupsDescription') }}</p>
        </div>
      </div>
      <button v-if="canManage && !editing" class="btn-primary text-xs flex items-center gap-1.5" data-testid="new-group" @click="openNew">
        <Plus class="w-3.5 h-3.5" />{{ t('rbac.newGroup') }}
      </button>
    </div>

    <!-- Editor -->
    <div v-if="editing" class="rounded-xl border border-brand-500/40 bg-gray-900/80 px-5 py-4 space-y-3" data-testid="group-editor">
      <p class="text-sm font-semibold text-gray-100">{{ editing === 'new' ? t('rbac.newGroup') : t('rbac.editGroup') }}</p>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="label">{{ t('rbac.groupName') }}</label>
          <input v-model="form.name" class="input" data-testid="group-name" />
        </div>
        <div>
          <label class="label">{{ t('rbac.groupDescription') }}</label>
          <input v-model="form.description" class="input" />
        </div>
        <div>
          <label class="label">{{ t('rbac.groupRole') }}</label>
          <RoleSelect v-model="form.role" :roles="roles" allow-empty :disabled="!canAssignRole" data-testid="group-role" />
          <p class="text-[10px] text-gray-500 mt-1">{{ t('rbac.groupRoleHint') }}</p>
        </div>
      </div>
      <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
      <div class="flex gap-2">
        <button class="btn-primary text-xs" :disabled="saving || !form.name.trim()" data-testid="save-group" @click="save">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <button class="btn-secondary text-xs" @click="editing = null">{{ t('common.cancel') }}</button>
      </div>
    </div>

    <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>
    <p v-else-if="!groups.length" class="text-xs text-gray-400 italic">{{ t('rbac.noGroups') }}</p>

    <div v-else class="space-y-2">
      <div v-for="group in groups" :key="group.id" class="rounded-xl border border-gray-800 bg-gray-900/60" :data-group="group.name">
        <div class="flex items-center gap-3 px-4 py-3">
          <button class="flex items-center gap-3 flex-1 min-w-0 text-left" @click="toggleOpen(group)">
            <component :is="openId === group.id ? ChevronDown : ChevronRight" class="w-4 h-4 text-gray-500" />
            <Users class="w-4 h-4 shrink-0" style="color:rgb(var(--brand-500))" />
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-gray-100">{{ group.name }}</span>
                <span v-if="group.role" class="text-[10px] px-1.5 py-0.5 rounded-sm border border-sky-800/50 bg-sky-900/30 text-sky-400">{{ roleLabel(group.role, roles) }}</span>
              </div>
              <p class="text-xs text-gray-400">
                {{ t('rbac.memberCount', { n: group.member_count }) }}
                <template v-if="group.project_grants"> · {{ t('rbac.projectGrants', { n: group.project_grants }) }}</template>
                <template v-if="group.description"> · {{ group.description }}</template>
              </p>
            </div>
          </button>
          <template v-if="canManage">
            <button class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" @click="openEdit(group)">
              <Pencil class="w-3.5 h-3.5" />
            </button>
            <button class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20" @click="remove(group)">
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </template>
        </div>

        <!-- Members -->
        <div v-if="openId === group.id && detail" class="px-4 pb-4 space-y-2">
          <div v-if="canManage" class="flex items-center gap-2">
            <select v-model="toAdd" multiple class="input text-xs h-20 flex-1" data-testid="group-add-members">
              <option v-for="m in addable" :key="m.id" :value="m.id">{{ m.name }} — {{ m.email }}</option>
            </select>
            <button class="btn-secondary text-xs self-start" :disabled="!toAdd.length" @click="addMembers">{{ t('rbac.addMembers') }}</button>
          </div>
          <p v-if="!detail.members.length" class="text-xs text-gray-400 italic">{{ t('rbac.noMembers') }}</p>
          <div v-for="m in detail.members" :key="m.id" class="flex items-center gap-2 text-xs rounded-md border border-gray-800 px-3 py-2">
            <span class="font-medium text-gray-100">{{ m.name }}</span>
            <span class="text-gray-400">{{ m.email }}</span>
            <span v-if="m.source === 'sso'" class="text-[10px] px-1.5 py-0.5 rounded-sm border border-emerald-800/40 bg-emerald-900/30 text-emerald-400" :title="t('rbac.sourceSsoHint')">{{ t('rbac.sourceSso') }}</span>
            <span class="flex-1" />
            <button v-if="canManage" class="text-gray-400 hover:text-red-400" :title="t('rbac.removeMember')" @click="removeMember(m)">
              <X class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

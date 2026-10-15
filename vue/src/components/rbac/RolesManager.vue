<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Pencil, Trash2, ShieldCheck, ChevronDown, ChevronRight } from 'lucide-vue-next'
import { rbacApi } from '@/api/rbac'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useRbacLabels, minimalPermissions } from '@/composables/useRbac'
import PermissionMatrix from './PermissionMatrix.vue'
import RoleSelect from './RoleSelect.vue'

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const perms = usePermissionsStore()
const { roleLabel, roleDescription } = useRbacLabels()

const catalog     = ref([])
const roles       = ref([])
const defaultRole = ref(null)
const loading     = ref(true)
const expanded    = ref(null)

const editing = ref(null) // null | 'new' | role key
const form    = ref({ name: '', description: '', permissions: [] })
const saving  = ref(false)
const error   = ref(null)

const canManage = computed(() => perms.can('roles.manage'))
const builtin   = computed(() => roles.value.filter(r => r.builtin))
const custom    = computed(() => roles.value.filter(r => !r.builtin))
// The editor hands out only what they hold (the API refuses the rest)
const allowed   = computed(() => perms.platformAdmin ? null : perms.permissions)

async function load() {
  loading.value = true
  try {
    const [c, r] = await Promise.all([rbacApi.catalog(), rbacApi.roles()])
    catalog.value     = c.data.permissions ?? []
    roles.value       = r.data.roles ?? []
    defaultRole.value = r.data.default_role
  } finally {
    loading.value = false
  }
}

function openNew() {
  editing.value = 'new'
  form.value = { name: '', description: '', permissions: [] }
  error.value = null
}

function openEdit(role) {
  editing.value = role.key
  form.value = {
    name: role.name,
    description: role.description || '',
    permissions: minimalPermissions(role.permissions, catalog.value),
  }
  error.value = null
}

function cancel() {
  editing.value = null
  error.value = null
}

async function save() {
  saving.value = true
  error.value  = null
  try {
    const payload = { ...form.value }
    if (editing.value === 'new') await rbacApi.createRole(payload)
    else await rbacApi.updateRole(editing.value, payload)
    toast.success(t('rbac.saved'))
    editing.value = null
    await load()
  } catch (e) {
    error.value = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
  } finally {
    saving.value = false
  }
}

async function remove(role) {
  const usage = role.usage || { members: 0, groups: 0, projects: 0 }
  const ok = await confirm({
    title: t('rbac.deleteRole'),
    message: t('rbac.deleteRoleConfirm', { name: role.name, ...usage }),
    destructive: true,
  })
  if (!ok) return
  try {
    await rbacApi.deleteRole(role.key)
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function changeDefault(role) {
  try {
    const { data } = await rbacApi.setDefaultRole(role)
    defaultRole.value = data.default_role
    toast.success(t('rbac.saved'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-5" data-testid="roles-manager">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('rbac.rolesTitle') }}</h3>
          <p class="text-xs text-gray-400 mt-0.5">{{ t('rbac.rolesDescription') }}</p>
        </div>
      </div>
      <button v-if="canManage && !editing" class="btn-primary text-xs flex items-center gap-1.5" data-testid="new-role" @click="openNew">
        <Plus class="w-3.5 h-3.5" />{{ t('rbac.newRole') }}
      </button>
    </div>

    <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>

    <template v-else>
      <!-- Default role -->
      <div class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3 flex items-center gap-4">
        <div class="flex-1 min-w-0">
          <p class="text-sm font-semibold text-gray-100">{{ t('rbac.defaultRole') }}</p>
          <p class="text-xs text-gray-400">{{ t('rbac.defaultRoleHint') }}</p>
        </div>
        <RoleSelect class="w-56" :model-value="defaultRole" :roles="roles" :disabled="!canManage"
          data-testid="default-role" @update:model-value="changeDefault" />
      </div>

      <!-- Editor -->
      <div v-if="editing" class="rounded-xl border border-brand-500/40 bg-gray-900/80 px-5 py-4 space-y-4" data-testid="role-editor">
        <p class="text-sm font-semibold text-gray-100">{{ editing === 'new' ? t('rbac.newRole') : t('rbac.editRole') }}</p>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">{{ t('rbac.roleName') }}</label>
            <input v-model="form.name" class="input" data-testid="role-name" />
          </div>
          <div>
            <label class="label">{{ t('rbac.roleDescriptionLabel') }}</label>
            <input v-model="form.description" class="input" />
          </div>
        </div>
        <div>
          <label class="label mb-2">{{ t('rbac.permissionsLabel') }}</label>
          <PermissionMatrix v-model="form.permissions" :catalog="catalog" :allowed="allowed" />
        </div>
        <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
        <div class="flex gap-2">
          <button class="btn-primary text-xs" :disabled="saving || !form.name.trim()" data-testid="save-role" @click="save">
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
          <button class="btn-secondary text-xs" @click="cancel">{{ t('common.cancel') }}</button>
        </div>
      </div>

      <!-- Built-in -->
      <div class="space-y-2">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ t('rbac.builtin') }}</p>
        <div v-for="role in builtin" :key="role.key" class="rounded-xl border border-gray-800 bg-gray-900/60" :data-role="role.key">
          <button class="w-full flex items-center gap-3 px-4 py-3 text-left" @click="expanded = expanded === role.key ? null : role.key">
            <ShieldCheck class="w-4 h-4 shrink-0" style="color:rgb(var(--brand-500))" />
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-gray-100">{{ roleLabel(role) }}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded border border-gray-700 text-gray-400">{{ t('rbac.readOnly') }}</span>
                <span class="text-[10px] font-mono text-gray-500">{{ role.key }}</span>
              </div>
              <p class="text-xs text-gray-400">{{ roleDescription(role) }}</p>
            </div>
            <span class="text-xs text-gray-400">{{ role.permissions.length === catalog.length ? t('rbac.allPermissions') : t('rbac.permissionsCount', { n: role.permissions.length }) }}</span>
            <component :is="expanded === role.key ? ChevronDown : ChevronRight" class="w-4 h-4 text-gray-500" />
          </button>
          <div v-if="expanded === role.key" class="px-4 pb-4">
            <PermissionMatrix :model-value="role.permissions" :catalog="catalog" readonly />
          </div>
        </div>
      </div>

      <!-- Custom -->
      <div class="space-y-2">
        <p class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">{{ t('rbac.custom') }}</p>
        <p v-if="!custom.length" class="text-xs text-gray-400 italic">{{ t('rbac.noCustomRoles') }}</p>
        <div v-for="role in custom" :key="role.key" class="rounded-xl border border-gray-800 bg-gray-900/60" :data-role="role.key">
          <div class="flex items-center gap-3 px-4 py-3">
            <button class="flex-1 min-w-0 text-left" @click="expanded = expanded === role.key ? null : role.key">
              <div class="flex items-center gap-2">
                <span class="text-sm font-semibold text-gray-100">{{ role.name }}</span>
                <span class="text-[10px] font-mono text-gray-500">{{ role.key }}</span>
              </div>
              <p class="text-xs text-gray-400">{{ role.description }}</p>
              <p v-if="role.usage" class="text-[11px] text-gray-500 mt-0.5">{{ t('rbac.usage', role.usage) }}</p>
            </button>
            <span class="text-xs text-gray-400">{{ t('rbac.permissionsCount', { n: role.permissions.length }) }}</span>
            <template v-if="canManage">
              <button class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :disabled="role.grantable === false" @click="openEdit(role)">
                <Pencil class="w-3.5 h-3.5" />
              </button>
              <button class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20" :disabled="role.grantable === false" @click="remove(role)">
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </template>
          </div>
          <div v-if="expanded === role.key" class="px-4 pb-4">
            <PermissionMatrix :model-value="role.permissions" :catalog="catalog" readonly />
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

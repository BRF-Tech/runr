<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUsersManager } from '@/composables/useUsersManager'
import { usePermissionsStore } from '@/stores/permissions'
import { useRbacLabels } from '@/composables/useRbac'
import RoleSelect from '@/components/rbac/RoleSelect.vue'
import { Plus, ShieldOff, ShieldCheck } from 'lucide-vue-next'

const { t } = useI18n()
const perms = usePermissionsStore()
const { roleLabel } = useRbacLabels()

const {
  users, loading, search, currentUserId,
  editingId, editForm, editError, editSaving,
  addMode, addForm, addError, addSaving,
  loadUsers, onSearch,
  openAdd, cancelAdd, submitAdd,
  openEdit, cancelEdit, submitEdit,
  reset2FA, deleteUser,
  roles, loadRoles, setRole, memberGroups,
} = useUsersManager()

const canInvite   = computed(() => perms.can('members.invite'))
const canUpdate   = computed(() => perms.can('members.update'))
const canRemove   = computed(() => perms.can('members.remove'))
const canSetRoles = computed(() => perms.can('roles.manage'))

onMounted(() => { loadUsers(); loadRoles() })

defineExpose({ openAdd })
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <div class="flex items-center gap-3">
        <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
        <div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('users.title') }}</h3>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <input data-tour="users-search" v-model="search" type="text" class="input w-64 text-xs"
          :placeholder="t('users.searchPlaceholder')" @input="onSearch" />
        <button v-if="canInvite" data-tour="users-add-btn" class="btn-primary text-xs whitespace-nowrap flex items-center gap-1.5" @click="openAdd">
          <Plus class="w-3.5 h-3.5" />{{ t('users.addBtn') }}
        </button>
      </div>
    </div>

    <!-- Add user form -->
    <div v-if="addMode" data-tour="users-add-form" class="rounded-md border border-gray-700 bg-gray-900/60 px-4 py-3 mb-4">
      <p class="text-xs font-medium text-gray-100 mb-3">{{ t('users.addTitle') }}</p>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">{{ t('users.name') }}</label>
          <input v-model="addForm.name" class="input" />
        </div>
        <div>
          <label class="label">{{ t('users.username') }}</label>
          <input v-model="addForm.username" class="input font-mono" />
        </div>
        <div>
          <label class="label">{{ t('users.email') }}</label>
          <input v-model="addForm.email" type="email" class="input" />
        </div>
        <div>
          <label class="label">{{ t('users.password') }}</label>
          <input v-model="addForm.password" type="password" class="input" />
        </div>
        <div v-if="roles.length">
          <label class="label">{{ t('rbac.role') }}</label>
          <RoleSelect v-model="addForm.role" :roles="roles" :disabled="!canSetRoles" data-testid="add-user-role" />
        </div>
      </div>
      <p v-if="addError" class="text-xs text-red-400 mt-2">{{ addError }}</p>
      <div class="flex gap-2 mt-3">
        <button class="btn-primary text-xs" :disabled="addSaving" @click="submitAdd">
          {{ addSaving ? t('common.saving') : t('common.save') }}
        </button>
        <button class="btn-secondary text-xs" @click="cancelAdd">{{ t('common.cancel') }}</button>
      </div>
    </div>

    <!-- Loading -->
    <p v-if="loading" class="text-xs text-gray-400 py-4 text-center">{{ t('common.loading') }}</p>

    <!-- User list -->
    <div v-else data-tour="users-list" class="space-y-1.5">
      <div v-for="user in users" :key="user.id"
        class="rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5 text-xs">

        <!-- Edit mode -->
        <template v-if="editingId === user.id">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('users.name') }}</label>
              <input v-model="editForm.name" class="input" />
            </div>
            <div>
              <label class="label">{{ t('users.email') }}</label>
              <input v-model="editForm.email" type="email" class="input" />
            </div>
          </div>
          <p v-if="editError" class="text-xs text-red-400 mt-2">{{ editError }}</p>
          <div class="flex gap-2 mt-2">
            <button class="btn-primary text-xs" :disabled="editSaving" @click="submitEdit(user)">
              {{ editSaving ? t('common.saving') : t('common.save') }}
            </button>
            <button class="btn-secondary text-xs" @click="cancelEdit">{{ t('common.cancel') }}</button>
          </div>
        </template>

        <!-- Display mode -->
        <template v-else>
          <div class="flex items-center gap-3">
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span class="font-medium text-gray-100">{{ user.name }}</span>
                <span class="text-gray-400 font-mono">@{{ user.username }}</span>
                <span class="text-gray-400">{{ user.email }}</span>
                <span v-if="user.two_factor_confirmed_at"
                  class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-emerald-900/40 border-emerald-800/40 text-emerald-400">
                  <ShieldCheck class="w-3 h-3" />2FA
                </span>
                <span v-for="g in memberGroups[user.id] || []" :key="g.id"
                  class="px-1.5 py-0.5 rounded-sm text-[10px] border border-gray-700 text-gray-400"
                  :title="g.source === 'sso' ? t('rbac.sourceSsoHint') : t('rbac.groups')">{{ g.name }}</span>
              </div>
            </div>
            <!-- Role (docs/permissions.md) -->
            <RoleSelect v-if="canSetRoles && user.id !== currentUserId && roles.length" class="w-40 shrink-0" size="xs"
              :model-value="user.role" :roles="roles" include-owner :data-user-role="user.username"
              @update:model-value="role => setRole(user, role)" />
            <span v-else-if="user.role" class="text-[10px] px-1.5 py-0.5 rounded-sm border border-gray-700 text-gray-300 shrink-0">{{ roleLabel(user.role, roles) }}</span>
            <!-- Reset 2FA (not self, only if 2FA enabled) -->
            <button v-if="canUpdate && user.id !== currentUserId && user.two_factor_confirmed_at"
              class="text-gray-400 hover:text-amber-400 transition-colors shrink-0"
              :title="t('users.reset2FA')"
              @click="reset2FA(user)">
              <ShieldOff class="w-3.5 h-3.5" />
            </button>
            <!-- Edit (not self) -->
            <button v-if="canUpdate && user.id !== currentUserId"
              class="text-gray-400 hover:text-gray-100 transition-colors shrink-0"
              @click="openEdit(user)">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
              </svg>
            </button>
            <!-- Delete (not self) -->
            <button v-if="canRemove && user.id !== currentUserId"
              class="text-gray-400 hover:text-red-400 transition-colors shrink-0"
              @click="deleteUser(user)">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
            </button>
          </div>
        </template>
      </div>

      <p v-if="users.length === 0" class="text-xs text-gray-400 italic text-center py-4">
        {{ t('users.noUsers') }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import { useTenantStore } from '../stores/tenant'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import TenantUsers from './TenantUsers.vue'
import { Users, Pencil, Trash2, ShieldCheck, ShieldOff } from 'lucide-vue-next'

const { t }       = useI18n()
const { confirm }  = useConfirm()
const toast        = useToast()
const tenantStore  = useTenantStore()

const isTenantMode = computed(() => !!tenantStore.activeTenant?.id)

const users     = ref([])
const loading   = ref(false)
const search    = ref('')
const editingId = ref(null)
const editForm  = ref({ name: '', email: '' })
const editError = ref(null)
const editSaving = ref(false)

const currentUserId = JSON.parse(localStorage.getItem('user') || '{}')?.id
let searchTimer = null

const tutorialStore = useTutorialStore()
function usersSteps(t) {
  return [
    { element: '[data-tour="tenancy-users-search"]', popover: { title: t('tenancy.tutorialUsersSearch'), description: t('tenancy.tutorialUsersSearchDesc') } },
    { element: '[data-tour="tenancy-users-list"]',   popover: { title: t('tenancy.tutorialUsersList'),   description: t('tenancy.tutorialUsersListDesc') } },
  ]
}
const { start: startTutorial } = createTutorial(usersSteps)
watch(() => tutorialStore.restartCounter, () => { nextTick(() => startTutorial()) })

onMounted(() => { if (!isTenantMode.value) loadUsers() })
watch(isTenantMode, (v) => { if (!v) loadUsers() })

async function loadUsers() {
  loading.value = true
  try {
    const params = search.value.trim() ? { search: search.value.trim() } : {}
    const { data } = await tenantsApi.listAllUsers(params)
    users.value = data.users ?? (Array.isArray(data) ? data : [])
  } finally {
    loading.value = false
  }
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadUsers, 300)
}

function openEdit(user) {
  editingId.value = user.id
  editForm.value = { name: user.name, email: user.email }
  editError.value = null
}

function cancelEdit() {
  editingId.value = null
  editError.value = null
}

async function submitEdit(user) {
  editSaving.value = true
  editError.value  = null
  try {
    await tenantsApi.updateAnyUser(user.id, editForm.value)
    user.name  = editForm.value.name
    user.email = editForm.value.email
    editingId.value = null
    toast.success(t('tenancy.userUpdated'))
  } catch (e) {
    editError.value = e.response?.data?.message
      ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
      ?? t('common.error')
  } finally {
    editSaving.value = false
  }
}

async function toggleAdmin(user) {
  try {
    const { data } = await tenantsApi.toggleAdmin(user.id)
    user.is_admin = data.is_admin
    toast.success(t('tenancy.adminToggled'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function reset2FA(user) {
  const ok = await confirm({
    title:   t('tenancy.reset2FATitle'),
    message: t('tenancy.reset2FAConfirm', { name: user.name }),
  })
  if (!ok) return
  try {
    await tenantsApi.reset2FA(user.id)
    user.two_factor_confirmed_at = null
    toast.success(t('tenancy.reset2FASuccess'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function deleteUser(user) {
  const ok = await confirm({
    title:       t('tenancy.deleteUserTitle'),
    message:     t('tenancy.deleteUserConfirm', { name: user.name }),
    destructive: true,
  })
  if (!ok) return
  try {
    await tenantsApi.deleteAnyUser(user.id)
    users.value = users.value.filter(u => u.id !== user.id)
    toast.success(t('tenancy.userDeleted'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

function roleBadge(role) {
  const base = 'px-1.5 py-0.5 rounded text-[10px] font-medium border'
  switch (role) {
    case 'owner': return `${base} bg-violet-900/40 border-violet-800/40 text-violet-400`
    case 'admin': return `${base} bg-emerald-900/40 border-emerald-800/40 text-emerald-400`
    case 'member':
    case 'developer': return `${base} bg-blue-900/40 border-blue-800/40 text-blue-400`
    default:      return `${base} bg-gray-800/40 border-gray-700/40 text-gray-400`
  }
}

function initials(user) {
  const name = user.name || user.email || '?'
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}
</script>

<template>
  <div>
    <!-- Tenant mode: delegate to TenantUsers -->
    <TenantUsers v-if="isTenantMode" :tenant-id="tenantStore.activeTenant.id" />

    <!-- Central admin mode -->
    <template v-else>
      <!-- Header -->
      <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
          <div class="w-1 h-8 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <h3 class="text-base font-semibold text-gray-100">{{ t('tenancy.allUsers') }}</h3>
        </div>
        <input v-model="search" type="text" class="input w-64 text-xs" data-tour="tenancy-users-search"
          :placeholder="t('tenancy.searchUsers')" @input="onSearch" />
      </div>

      <p v-if="loading" class="text-xs text-gray-400 py-6 text-center">{{ t('common.loading') }}</p>

      <div v-else data-tour="tenancy-users-list" class="space-y-2">
        <div v-for="user in users" :key="user.id"
          class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3 text-xs transition-all hover:border-gray-700">

          <!-- Edit mode -->
          <template v-if="editingId === user.id">
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label">{{ t('tenancy.userName') }}</label>
                <input v-model="editForm.name" class="input" />
              </div>
              <div>
                <label class="label">{{ t('tenancy.email') }}</label>
                <input v-model="editForm.email" type="email" class="input" />
              </div>
            </div>
            <p v-if="editError" class="text-xs text-red-400 mt-2">{{ editError }}</p>
            <div class="flex gap-2 mt-3">
              <button class="btn-primary text-xs" :disabled="editSaving" @click="submitEdit(user)">
                {{ editSaving ? t('common.saving') : t('common.save') }}
              </button>
              <button class="btn-secondary text-xs" @click="cancelEdit">{{ t('common.cancel') }}</button>
            </div>
          </template>

          <!-- Display mode -->
          <template v-else>
            <div class="flex items-center gap-3">
              <!-- Avatar -->
              <div class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 text-[11px] font-bold bg-gray-800 border border-gray-700 text-gray-100">
                {{ initials(user) }}
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                  <span class="text-sm font-semibold text-gray-100">{{ user.name }}</span>
                  <span class="text-gray-400 font-mono text-[11px]">{{ user.email }}</span>
                  <span v-if="user.is_admin"
                    class="px-1.5 py-0.5 rounded text-[10px] font-medium border bg-amber-900/40 border-amber-800/40 text-amber-400">
                    {{ t('tenancy.platformAdmin') }}
                  </span>
                  <span v-if="user.two_factor_confirmed_at"
                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium border bg-emerald-900/40 border-emerald-800/40 text-emerald-400">
                    <ShieldCheck class="w-3 h-3" />2FA
                  </span>
                </div>
                <!-- Tenant memberships -->
                <div v-if="user.tenants?.length" class="flex flex-wrap gap-1.5 mt-1">
                  <span v-for="tenant in user.tenants" :key="tenant.id" :class="roleBadge(tenant.role)">
                    {{ tenant.name }}
                  </span>
                </div>
              </div>
              <!-- Admin toggle -->
              <button v-if="user.id !== currentUserId"
                class="p-1.5 rounded-md transition-colors shrink-0"
                :class="user.is_admin
                  ? 'text-amber-400 hover:text-amber-300 hover:bg-amber-900/20'
                  : 'text-gray-400 hover:text-amber-400 hover:bg-amber-900/20'"
                :title="t('tenancy.toggleAdmin')"
                @click="toggleAdmin(user)">
                <ShieldCheck class="w-4 h-4" />
              </button>
              <!-- Reset 2FA -->
              <button v-if="user.id !== currentUserId && user.two_factor_confirmed_at"
                class="p-1.5 rounded-md text-gray-400 hover:text-amber-400 hover:bg-amber-900/20 transition-colors shrink-0"
                :title="t('tenancy.reset2FA')"
                @click="reset2FA(user)">
                <ShieldOff class="w-3.5 h-3.5" />
              </button>
              <!-- Edit -->
              <button v-if="user.id !== currentUserId"
                class="p-1.5 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors shrink-0"
                @click="openEdit(user)">
                <Pencil class="w-3.5 h-3.5" />
              </button>
              <!-- Delete -->
              <button v-if="user.id !== currentUserId"
                class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors shrink-0"
                @click="deleteUser(user)">
                <Trash2 class="w-3.5 h-3.5" />
              </button>
            </div>
          </template>
        </div>

        <!-- Empty state -->
        <div v-if="users.length === 0" class="py-10 text-center">
          <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
            <Users class="w-6 h-6 text-gray-400" />
          </div>
          <p class="text-sm text-gray-400">{{ t('tenancy.noUsersFound') }}</p>
        </div>
      </div>
    </template>
  </div>
</template>

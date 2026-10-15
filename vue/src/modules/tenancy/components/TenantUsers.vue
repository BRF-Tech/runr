<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import { rbacApi } from '@/api/rbac'
import { useRbacLabels } from '@/composables/useRbac'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import { UserPlus, X, RefreshCw, Mail, CheckCircle, AlertTriangle, Send, ShieldCheck, ShieldOff } from 'lucide-vue-next'

const props = defineProps({
  tenantId: { type: [String, Number], required: true },
})

const { t }       = useI18n()
const { confirm }  = useConfirm()
const toast        = useToast()

const users       = ref([])
const invitations = ref([])
const loading     = ref(false)
const showForm    = ref(false)

const currentUserId = JSON.parse(localStorage.getItem('user') || '{}')?.id

// Built-in and custom roles of the tenant (docs/permissions.md); Owner is not handed out here
const allRoles    = ref([])
const roles       = computed(() => allRoles.value.filter(r => r.key !== 'owner'))
const defaultRole = ref('developer')
const { roleLabel } = useRbacLabels()
const label = (role) => roleLabel(role, allRoles.value)

async function loadRoles() {
  try {
    const { data } = await rbacApi.roles()
    allRoles.value    = data.roles ?? []
    defaultRole.value = data.default_role || 'developer'
    if (!showForm.value) form.value.role = defaultRole.value
  } catch { /* role names fall back to keys */ }
}

const form         = ref({ email: '', role: 'developer' })
const formError    = ref(null)
const formSaving   = ref(false)
const searchResult = ref(null)
const searching    = ref(false)
let searchTimer    = null

const tutorialStore = useTutorialStore()
function tenantUsersSteps(t) {
  return [
    { element: '[data-tour="tenant-users-form"]', popover: { title: t('tenancy.tutorialTenantUsersForm'), description: t('tenancy.tutorialTenantUsersFormDesc') } },
    { element: '[data-tour="tenant-users-list"]', popover: { title: t('tenancy.tutorialTenantUsersList'), description: t('tenancy.tutorialTenantUsersListDesc') } },
  ]
}
const { start: startTutorial } = createTutorial(tenantUsersSteps)
watch(() => tutorialStore.restartCounter, () => {
  openAddUser()
  nextTick(() => startTutorial())
})

onMounted(() => { loadUsers(); loadRoles() })

async function loadUsers() {
  loading.value = true
  try {
    const { data } = await tenantsApi.listUsers(props.tenantId)
    users.value = Array.isArray(data.users) ? data.users : []
    invitations.value = Array.isArray(data.invitations) ? data.invitations : []
  } finally {
    loading.value = false
  }
}

function openAddUser() {
  form.value = { email: '', role: defaultRole.value }
  formError.value = null
  searchResult.value = null
  showForm.value = true
}

function onEmailInput() {
  clearTimeout(searchTimer)
  searchResult.value = null
  formError.value = null
  const email = form.value.email.trim()
  if (!email || !email.includes('@') || !email.includes('.')) return
  searchTimer = setTimeout(() => searchEmail(email), 400)
}

async function searchEmail(email) {
  searching.value = true
  try {
    const { data } = await tenantsApi.searchEmail(props.tenantId, email)
    searchResult.value = data
  } catch {
    searchResult.value = null
  } finally {
    searching.value = false
  }
}

async function submitUser() {
  formSaving.value = true
  formError.value  = null
  try {
    const { data } = await tenantsApi.addUser(props.tenantId, form.value)
    const action = data.action
    toast.success(action === 'invited' ? t('tenancy.invitationSent') : t('tenancy.userAdded'))
    showForm.value = false
    searchResult.value = null
    await loadUsers()
  } catch (e) {
    formError.value = e.response?.data?.message
      ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
      ?? t('common.error')
  } finally {
    formSaving.value = false
  }
}

async function changeRole(user, newRole) {
  const previous = user.role
  user.role = newRole
  try {
    await tenantsApi.updateUser(props.tenantId, user.id, { role: newRole })
    toast.success(t('tenancy.roleUpdated'))
  } catch (e) {
    user.role = previous
    toast.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error'))
  }
}

async function reset2FA(user) {
  const ok = await confirm({
    title:   t('tenancy.reset2FATitle'),
    message: t('tenancy.reset2FAConfirm', { name: user.name ?? user.email }),
  })
  if (!ok) return
  try {
    await tenantsApi.resetTenantUser2FA(props.tenantId, user.id)
    user.two_factor_confirmed_at = null
    toast.success(t('tenancy.reset2FASuccess'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function removeUser(user) {
  const ok = await confirm({
    title:       t('tenancy.removeUserTitle'),
    message:     t('tenancy.removeUserConfirm', { name: user.name ?? user.email }),
    destructive: true,
  })
  if (!ok) return
  try {
    await tenantsApi.removeUser(props.tenantId, user.id)
    toast.success(t('tenancy.userRemoved'))
    await loadUsers()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function cancelInvitation(inv) {
  const ok = await confirm({
    title:       t('tenancy.cancelInvitationTitle'),
    message:     t('tenancy.cancelInvitationConfirm', { email: inv.email }),
    destructive: true,
  })
  if (!ok) return
  try {
    await tenantsApi.cancelInvitation(props.tenantId, inv.id)
    toast.success(t('tenancy.invitationCancelled'))
    await loadUsers()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function resendInvitation(inv) {
  try {
    await tenantsApi.resendInvitation(props.tenantId, inv.id)
    toast.success(t('tenancy.invitationResent'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

const roleBadges = { owner: 'bg-violet-900/40 border-violet-800/40 text-violet-400', admin: 'bg-emerald-900/40 border-emerald-800/40 text-emerald-400', member: 'bg-blue-900/40 border-blue-800/40 text-blue-400', developer: 'bg-blue-900/40 border-blue-800/40 text-blue-400' }
const roleBadgeClass = (role) => `px-1.5 py-0.5 rounded-sm text-[10px] font-medium border ${roleBadges[role] ?? 'bg-gray-800/40 border-gray-700/40 text-gray-400'}`
const initials = (user) => (user.name || user.email || '?').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
const submitLabel = () => formSaving.value ? t('common.saving') : searchResult.value && !searchResult.value.found ? t('tenancy.sendInvitation') : t('tenancy.addUser')
const canSubmit = () => !!form.value.email.trim() && !searchResult.value?.already_member && !searchResult.value?.pending_invitation
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('tenancy.users') }}</p>
      <button class="btn-primary text-xs flex items-center gap-1.5" @click="openAddUser">
        <UserPlus class="w-3.5 h-3.5" />{{ t('tenancy.addUser') }}
      </button>
    </div>

    <!-- Add user form -->
    <div v-if="showForm" data-tour="tenant-users-form" class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-4 mb-4 space-y-3">
      <p class="text-sm font-semibold text-gray-100">{{ t('tenancy.addUser') }}</p>
      <div class="grid grid-cols-3 gap-3">
        <div class="col-span-2">
          <label class="label">{{ t('tenancy.email') }}</label>
          <input v-model="form.email" type="email" class="input" :placeholder="t('tenancy.emailPlaceholder')"
            @input="onEmailInput" />
        </div>
        <div>
          <label class="label">{{ t('tenancy.role') }}</label>
          <select v-model="form.role" class="input">
            <option v-for="r in roles" :key="r.key" :value="r.key" :disabled="r.grantable === false">{{ label(r.key) }}</option>
          </select>
        </div>
      </div>

      <div v-if="searching" class="flex items-center gap-2 text-xs text-gray-400 py-1">
        <RefreshCw class="w-3 h-3 animate-spin" />{{ t('common.loading') }}
      </div>
      <div v-else-if="searchResult" class="rounded-lg px-3 py-2 text-xs border">
        <div v-if="searchResult.already_member" class="flex items-center gap-2 text-amber-400 border-amber-800/40 bg-amber-900/20"><AlertTriangle class="w-3.5 h-3.5 shrink-0" />{{ t('tenancy.alreadyMember') }}</div>
        <div v-else-if="searchResult.pending_invitation" class="flex items-center gap-2 text-amber-400"><AlertTriangle class="w-3.5 h-3.5 shrink-0" />{{ t('tenancy.pendingInvitation') }}</div>
        <div v-else-if="searchResult.found" class="flex items-center gap-2 text-emerald-400"><CheckCircle class="w-3.5 h-3.5 shrink-0" />{{ t('tenancy.userFound', { name: searchResult.user.name }) }}</div>
        <div v-else class="flex items-center gap-2 text-blue-400"><Send class="w-3.5 h-3.5 shrink-0" />{{ t('tenancy.willInvite') }}</div>
      </div>
      <p v-if="formError" class="text-xs text-red-400">{{ formError }}</p>
      <div class="flex gap-2">
        <button class="btn-primary text-xs" :disabled="formSaving || !canSubmit()" @click="submitUser">{{ submitLabel() }}</button>
        <button class="btn-secondary text-xs" @click="showForm = false">{{ t('common.cancel') }}</button>
      </div>
    </div>

    <p v-if="loading" class="text-xs text-gray-400 py-3 text-center">{{ t('common.loading') }}</p>
    <template v-else>
      <div data-tour="tenant-users-list" class="space-y-1.5">
        <div v-for="user in users" :key="'u-' + user.id"
          class="flex items-center gap-3 rounded-lg border border-gray-800 bg-gray-900/40 px-3 py-2.5 text-xs">
          <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 text-[10px] font-bold bg-gray-800 border border-gray-700 text-gray-100">
            {{ initials(user) }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="font-medium text-gray-100 truncate">{{ user.name ?? user.email }}</span>
              <span class="text-gray-400 font-mono text-[11px] truncate">{{ user.email }}</span>
              <span v-if="user.two_factor_confirmed_at"
                class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-emerald-900/40 border-emerald-800/40 text-emerald-400">
                <ShieldCheck class="w-3 h-3" />2FA
              </span>
            </div>
          </div>
          <!-- Reset 2FA -->
          <button v-if="user.role !== 'owner' && user.id !== currentUserId && user.two_factor_confirmed_at"
            class="p-1 rounded-md text-gray-400 hover:text-amber-400 hover:bg-amber-900/20 transition-colors shrink-0"
            :title="t('tenancy.reset2FA')"
            @click="reset2FA(user)">
            <ShieldOff class="w-3.5 h-3.5" />
          </button>
          <span v-if="user.role === 'owner' || user.id === currentUserId" :class="roleBadgeClass(user.role)">{{ label(user.role) }}</span>
          <select v-else class="bg-gray-800 border border-gray-700 text-gray-100 text-[11px] rounded-sm px-1.5 py-0.5 focus:outline-hidden"
            :value="user.role" @change="changeRole(user, $event.target.value)">
            <option v-for="r in roles" :key="r.key" :value="r.key" :disabled="r.grantable === false && r.key !== user.role">{{ label(r.key) }}</option>
          </select>
          <button v-if="user.role !== 'owner' && user.id !== currentUserId"
            class="p-1 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors shrink-0" @click="removeUser(user)">
            <X class="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      <!-- Pending invitations -->
      <div v-if="invitations.length" class="mt-4">
        <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider mb-2">{{ t('tenancy.pendingInvitations') }}</p>
        <div class="space-y-1.5">
          <div v-for="inv in invitations" :key="'i-' + inv.id"
            class="flex items-center gap-3 rounded-lg border border-dashed border-gray-700 bg-gray-900/20 px-3 py-2.5 text-xs">
            <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 bg-gray-800/60 border border-gray-700/60">
              <Mail class="w-3.5 h-3.5 text-gray-400" />
            </div>
            <div class="flex-1 min-w-0">
              <span class="text-gray-100 font-mono truncate">{{ inv.email }}</span>
            </div>
            <span :class="roleBadgeClass(inv.role)">{{ label(inv.role) }}</span>
            <button class="p-1 rounded-md text-gray-400 hover:text-blue-400 hover:bg-blue-900/20 transition-colors"
              :title="t('tenancy.resend')" @click="resendInvitation(inv)">
              <RefreshCw class="w-3.5 h-3.5" />
            </button>
            <button class="p-1 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors shrink-0"
              @click="cancelInvitation(inv)">
              <X class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
      </div>

      <p v-if="users.length === 0 && invitations.length === 0" class="text-xs text-gray-400 italic text-center py-4">
        {{ t('tenancy.noUsers') }}
      </p>
    </template>
  </div>
</template>

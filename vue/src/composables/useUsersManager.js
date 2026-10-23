import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usersApi } from '@/api/users'
import { rbacApi } from '@/api/rbac'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useLatest } from '@/composables/useLatest'

export function useUsersManager() {
  const { t } = useI18n()
  const { confirm } = useConfirm()
  const toast = useToast()

  const users     = ref([])
  const loading   = ref(false)
  const search    = ref('')
  const editingId = ref(null)
  const editForm  = ref({ name: '', email: '' })
  const editError = ref(null)
  const editSaving = ref(false)

  const addMode   = ref(false)
  const addForm   = ref({ name: '', username: '', email: '', password: '' })
  const addError  = ref(null)
  const addSaving = ref(false)

  const currentUserId = JSON.parse(localStorage.getItem('user') || '{}')?.id
  let searchTimer = null

  // The users of the search typed last; never throws (a failed reload after a saved change is
  // not that change's error)
  const latest = useLatest()
  async function loadUsers() {
    const current = latest.begin()
    loading.value = true
    try {
      const params = search.value.trim() ? { search: search.value.trim() } : {}
      const { data } = await usersApi.list(params)
      if (current()) users.value = data.users ?? []
    } catch { /* the list keeps what it showed */ } finally {
      if (current()) loading.value = false
    }
  }

  function onSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(loadUsers, 300)
  }

  // ── Add ──
  function openAdd() {
    addMode.value = true
    addForm.value = { name: '', username: '', email: '', password: '', role: addForm.value.role ?? null }
    addError.value = null
  }

  function cancelAdd() {
    addMode.value = false
    addError.value = null
  }

  async function submitAdd() {
    addSaving.value = true
    addError.value  = null
    try {
      // The default role needs no grant: the API gives it to new users
      const payload = { ...addForm.value }
      if (!payload.role || payload.role === defaultRole.value) delete payload.role
      await usersApi.create(payload)
      addMode.value = false
      toast.success(t('users.created'))
      await loadUsers()
    } catch (e) {
      addError.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? t('common.error')
    } finally {
      addSaving.value = false
    }
  }

  // ── Edit ──
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
    // What was sent: the form may hold another user's values when the answer arrives
    const sent = { ...editForm.value }
    try {
      await usersApi.update(user.id, sent)
      user.name  = sent.name
      user.email = sent.email
      if (editingId.value === user.id) editingId.value = null
      toast.success(t('users.updated'))
    } catch (e) {
      editError.value = e.response?.data?.message
        ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
        ?? t('common.error')
    } finally {
      editSaving.value = false
    }
  }

  // ── Reset 2FA ──
  async function reset2FA(user) {
    const ok = await confirm({
      title:   t('users.reset2FATitle'),
      message: t('users.reset2FAConfirm', { name: user.name }),
    })
    if (!ok) return
    try {
      await usersApi.reset2FA(user.id)
      user.two_factor_confirmed_at = null
      toast.success(t('users.reset2FASuccess'))
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
    }
  }

  // ── Delete ──
  async function deleteUser(user) {
    const ok = await confirm({
      title:       t('users.deleteTitle'),
      message:     t('users.deleteConfirm', { name: user.name }),
      destructive: true,
    })
    if (!ok) return
    try {
      await usersApi.destroy(user.id)
      users.value = users.value.filter(u => u.id !== user.id)
      toast.success(t('users.deleted'))
    } catch (e) {
      toast.error(e.response?.data?.message ?? t('common.error'))
    }
  }

  // ── Roles (docs/permissions.md) ──
  const roles       = ref([])
  const defaultRole = ref(null)

  // Groups of every member (badges on the list; managed on the Groups tab)
  const memberGroups = ref({})

  async function loadRoles() {
    rbacApi.members()
      .then(({ data }) => { memberGroups.value = Object.fromEntries(data.members.map(m => [m.id, m.groups])) })
      .catch(() => {})
    try {
      const { data } = await rbacApi.roles()
      roles.value       = data.roles ?? []
      defaultRole.value = data.default_role
      if (!addForm.value.role) addForm.value.role = data.default_role
    } catch { /* role column stays read-only */ }
  }

  async function setRole(user, role) {
    if (!role || role === user.role) return
    const previous = user.role
    user.role = role
    try {
      await rbacApi.setMemberRole(user.id, role)
      toast.success(t('rbac.roleUpdated'))
    } catch (e) {
      user.role = previous
      toast.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error'))
    }
  }

  return {
    users, loading, search, currentUserId,
    editingId, editForm, editError, editSaving,
    addMode, addForm, addError, addSaving,
    loadUsers, onSearch,
    openAdd, cancelAdd, submitAdd,
    openEdit, cancelEdit, submitEdit,
    reset2FA, deleteUser,
    roles, loadRoles, setRole, memberGroups,
  }
}

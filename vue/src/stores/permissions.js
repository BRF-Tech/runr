import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { rbacApi } from '@/api/rbac'

/**
 * Effective permissions of the signed-in user in the current organization (GET /auth/permissions).
 * The API enforces every permission; the UI only hides or disables what the user cannot do.
 *
 *   can('projects.deploy', projectId)  — organization-wide, or through project access
 *   canAnywhere('projects.view')       — organization-wide or on at least one project
 */
export const usePermissionsStore = defineStore('permissions', () => {
  const loaded        = ref(false)
  const platformAdmin = ref(false)
  const edition       = ref('single')
  const role          = ref(null)
  const roles         = ref([])
  const permissions   = ref([])
  const projects      = ref({})
  const tokenScoped   = ref(false)

  let pending = null

  const orgSet = computed(() => new Set(permissions.value))

  function can(permission, projectId = null) {
    if (!loaded.value) return true // until loaded: show, the API decides
    if (platformAdmin.value && !tokenScoped.value) return true
    if (orgSet.value.has(permission)) return true
    return !!projectId && (projects.value[projectId] || []).includes(permission)
  }

  function canAnywhere(permission) {
    if (can(permission)) return true
    return Object.values(projects.value).some(list => list.includes(permission))
  }

  function apply(data) {
    // Not a permissions payload (an API without the endpoint): stay unloaded, the API decides
    if (!Array.isArray(data?.permissions)) return
    platformAdmin.value = !!data.platform_admin
    edition.value       = data.edition || 'single'
    role.value          = data.role ?? null
    roles.value         = data.roles || []
    permissions.value   = data.permissions || []
    projects.value      = data.projects || {}
    tokenScoped.value   = !!data.token_scoped
    loaded.value        = true
  }

  async function load() {
    if (!localStorage.getItem('token')) {
      reset()
      return
    }
    pending ??= rbacApi.myPermissions()
      .then(({ data }) => apply(data))
      .catch(() => { /* keep the previous state; the API still enforces */ })
      .finally(() => { pending = null })
    return pending
  }

  function reset() {
    loaded.value = false
    platformAdmin.value = false
    role.value = null
    roles.value = []
    permissions.value = []
    projects.value = {}
    tokenScoped.value = false
  }

  return {
    loaded, platformAdmin, edition, role, roles, permissions, projects, tokenScoped,
    can, canAnywhere, load, reset, apply,
  }
})

// Who is looking at the UI — the same rules the tenancy module uses (localStorage user /
// active_tenant) plus the roles & permissions store, shared by the white-label settings tabs and
// the onboarding wizard.
import { usePermissionsStore } from '@/stores/permissions'

export function isMultiTenantBuild() {
  return import.meta.env.VITE_TENANCY_ENABLED === 'true'
}

export function currentUser() {
  try { return JSON.parse(localStorage.getItem('user') || 'null') } catch { return null }
}

export function activeTenant() {
  try {
    const t = JSON.parse(localStorage.getItem('active_tenant') || 'null')
    return t?.id ? t : null
  } catch {
    return null
  }
}

export function isAuthenticated() {
  return !!localStorage.getItem('token')
}

/**
 * A permission of the signed-in user in the current organization (roles & permissions; the API
 * enforces every permission, this only decides what the console shows).
 */
export function canInOrg(permission) {
  try {
    return usePermissionsStore().can(permission)
  } catch {
    return !!currentUser()?.is_admin
  }
}

/**
 * Settings of the install (branding, signup): single-tenant whoever holds the permission,
 * multi-tenant the platform administrators outside a tenant.
 */
export function canManageInstall(permission, multiTenant = isMultiTenantBuild()) {
  return multiTenant ? isCentralAdmin(multiTenant) : canInOrg(permission)
}

/** A permission in the active tenant (multi-tenant). */
export function canInTenant(permission, multiTenant = isMultiTenantBuild()) {
  return multiTenant && !!activeTenant() && canInOrg(permission)
}

/** Multi-tenant central admin (admin + no active tenant). */
export function isCentralAdmin(multiTenant = isMultiTenantBuild()) {
  return multiTenant && !!currentUser()?.is_admin && !activeTenant()
}

/** Any member of the active tenant (multi-tenant). */
export function inTenantContext(multiTenant = isMultiTenantBuild()) {
  return multiTenant && isAuthenticated() && !!activeTenant()
}

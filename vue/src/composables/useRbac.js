import { useI18n } from 'vue-i18n'

/** Labels of permissions, permission groups and roles (built-in roles are translated). */
export function useRbacLabels() {
  const { t, te } = useI18n()
  const key = (permission) => permission.replace(/\./g, '_')

  function permLabel(permission) {
    const k = `rbac.perm.${key(permission)}`
    return te(k) ? t(k) : permission
  }

  function permHint(permission) {
    const k = `rbac.permHint.${key(permission)}`
    return te(k) ? t(k) : ''
  }

  function groupLabel(group) {
    const k = `rbac.group.${group}`
    return te(k) ? t(k) : group
  }

  /** role: { key, name, builtin } or a key (+ optional list of roles to look the name up in) */
  function roleLabel(role, roles = []) {
    const r = typeof role === 'string' ? (roles.find(x => x.key === role) || { key: role, name: role, builtin: true }) : role
    if (!r) return ''
    const k = `rbac.roleName_${r.key}`
    return r.builtin !== false && te(k) ? t(k) : (r.name || r.key)
  }

  function roleDescription(role) {
    const k = `rbac.roleDesc_${role.key}`
    return role.builtin && te(k) ? t(k) : (role.description || '')
  }

  return { permLabel, permHint, groupLabel, roleLabel, roleDescription }
}

/**
 * Permissions implied by a selection (catalog: [{ key, implies }]), transitively. Same rule as the
 * API (App\Support\Rbac\PermissionCatalog::expand).
 */
export function expandPermissions(selected, catalog) {
  const implies = Object.fromEntries(catalog.map(p => [p.key, p.implies || []]))
  const result = new Set()
  const queue = [...selected]
  while (queue.length) {
    const p = queue.pop()
    if (!(p in implies) || result.has(p)) continue
    result.add(p)
    queue.push(...implies[p])
  }
  return catalog.map(p => p.key).filter(k => result.has(k))
}

/** The smallest selection that expands to the same permissions (for editing a stored role). */
export function minimalPermissions(permissions, catalog) {
  return permissions.filter(p => {
    const others = permissions.filter(o => o !== p)
    return !expandPermissions(others, catalog).includes(p)
  })
}

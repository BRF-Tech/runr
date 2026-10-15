/**
 * Translate a notification record using its type + data fields.
 * Falls back to the stored title if no mapping exists.
 *
 * @param {Object} n  - notification record (type, data, title, level)
 * @param {Function} t - vue-i18n translation function
 */
export function notificationTitle(n, t) {
  const type = n.type  || ''
  const d    = n.data  || {}
  const reg  = d.region_name

  // ── Resource threshold ──────────────────────────────────────────────────────
  if (type === 'resource_threshold') {
    const key = d.resource_type === 'cpu'
      ? 'notifications.cpuThreshold'
      : 'notifications.memoryThreshold'
    return t(key, { region: reg, percentage: d.percentage })
  }

  // ── Knative: knative.{component}.{event} ───────────────────────────────────
  if (type.startsWith('knative.')) {
    const parts  = type.split('.')               // ['knative', 'operator', 'installed']
    const suffix = parts.slice(2).join('_')      // 'installed' | 'install_failed' | …
    const comp   = d.component || parts[1]
    const keyMap = {
      installed:        'notifications.knativeInstalled',
      install_failed:   'notifications.knativeInstallFailed',
      uninstalled:      'notifications.knativeUninstalled',
      uninstall_failed: 'notifications.knativeUninstallFailed',
    }
    if (keyMap[suffix]) return t(keyMap[suffix], { region: reg, component: comp })
  }

  // ── Sandbox: sandbox.{runtime}.{event} ─────────────────────────────────────
  if (type.startsWith('sandbox.')) {
    const parts   = type.split('.')
    const suffix  = parts.slice(2).join('_')
    const runtime = d.runtime || parts[1]
    const keyMap  = {
      installed:        'notifications.sandboxInstalled',
      install_failed:   'notifications.sandboxInstallFailed',
      uninstalled:      'notifications.sandboxUninstalled',
      uninstall_failed: 'notifications.sandboxUninstallFailed',
    }
    if (keyMap[suffix]) return t(keyMap[suffix], { region: reg, runtime })
  }

  // ── Redis ───────────────────────────────────────────────────────────────────
  const redisMap = {
    'redis.installed':     'notifications.redisInstalled',
    'redis.install_failed':'notifications.redisInstallFailed',
    'redis.removed':       'notifications.redisRemoved',
    'redis.remove_failed': 'notifications.redisRemoveFailed',
  }
  if (redisMap[type]) return t(redisMap[type], { region: reg })

  // ── Region ──────────────────────────────────────────────────────────────────
  if (type === 'region.created')              return t('notifications.regionCreated', { region: reg })
  if (type === 'region.create_failed')        return t('notifications.regionCreateFailed')
  if (type === 'region.migrated')             return t('notifications.regionMigrated', { region: reg })
  if (type === 'region.migrate_failed')       return t('notifications.regionMigrateFailed', { region: reg })
  if (type === 'region.migration_completed')  return t('notifications.regionMigrated', { region: reg })
  if (type === 'region.detached')             return t('notifications.regionDetached', { region: reg })
  if (type === 'region.detach_failed')        return t('notifications.regionDetachFailed', { region: reg })

  // ── Build ───────────────────────────────────────────────────────────────────
  const ver = d.version ?? ''
  if (type === 'build.completed') return t('notifications.buildCompleted', { project: d.project_name, version: ver })
  if (type === 'build.failed')    return t('notifications.buildFailed',    { project: d.project_name, version: ver })

  // ── Deployment ──────────────────────────────────────────────────────────────
  if (type === 'deployment.ready')  return t('notifications.deploymentReady',  { project: d.project_name })
  if (type === 'deployment.failed') return t('notifications.deploymentFailed', { project: d.project_name })

  // ── Demo ────────────────────────────────────────────────────────────────────
  if (type === 'demo.seeded'  || type === 'demo.deployed')      return t('notifications.demoSeeded')
  if (type === 'demo.seed_failed' || type === 'demo.deploy_failed') return t('notifications.demoSeedFailed')
  if (type === 'demo.removed')      return t('notifications.demoRemoved')
  if (type === 'demo.remove_failed') return t('notifications.demoRemoveFailed')

  // ── Tickets ─────────────────────────────────────────────────────────────────
  if (type === 'ticket.created') return t('notifications.ticketCreated', { subject: n.data?.ticket_subject || '' })
  if (type === 'ticket.reply')   return t('notifications.ticketReply',   { subject: n.data?.ticket_subject || '' })
  if (type === 'ticket.status')  return n.title || ''

  // ── Fallback ─────────────────────────────────────────────────────────────────
  return n.title || ''
}

/**
 * Returns a vue-router location for actionable notifications, or null.
 */
export function notificationAction(n) {
  if (n.type === 'ticket.created' || n.type === 'ticket.reply' || n.type === 'ticket.status') {
    const query = { tab: 'support-tickets', ticketId: n.data?.ticket_id }
    if (n.data?.tenant_id) query.tenantId = n.data.tenant_id
    return { path: '/settings', query }
  }
  return null
}

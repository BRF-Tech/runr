import { levelKey, pathLocation } from '@/utils/runtimePatches'

/** environment.promotion.<event> → title key: one waits for the reader's approval, or the reader's own changed */
const PROMOTION_TITLES = {
  'environment.promotion.pending_approval': 'notifications.promotionPending',
  'environment.promotion.approved':         'notifications.promotionApproved',
  'environment.promotion.rejected':         'notifications.promotionRejected',
  'environment.promotion.deployed':         'notifications.promotionDeployed',
  'environment.promotion.failed':           'notifications.promotionFailed',
}

/** Whether a notification is about a promotion between environments. */
export function isPromotionNotification(n) {
  return String(n?.type || '').startsWith('environment.promotion.')
}

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
  // A deploy that waited for its version to be built again for the region's registry was refused
  if (type === 'deployment.queued_failed') return t('notifications.queuedDeployFailed', { project: d.project_name, version: ver })

  // ── Demo ────────────────────────────────────────────────────────────────────
  if (type === 'demo.seeded'  || type === 'demo.deployed')      return t('notifications.demoSeeded')
  if (type === 'demo.seed_failed' || type === 'demo.deploy_failed') return t('notifications.demoSeedFailed')
  if (type === 'demo.removed')      return t('notifications.demoRemoved')
  if (type === 'demo.remove_failed') return t('notifications.demoRemoveFailed')

  // ── Tickets ─────────────────────────────────────────────────────────────────
  if (type === 'ticket.created') return t('notifications.ticketCreated', { subject: n.data?.ticket_subject || '' })
  if (type === 'ticket.reply')   return t('notifications.ticketReply',   { subject: n.data?.ticket_subject || '' })
  if (type === 'ticket.status')  return n.title || ''

  // ── Runtime patches (docs/runtimes.md#runtime-patches) ─────────────────────
  // A new patch, a migration, a dropped pin and an end-of-life reminder carry what the title
  // needs; a refused feed keeps the title the API wrote
  if (type === 'runtime_patch' && d.event === 'patch' && d.tag) {
    return t('notifications.runtimePatch', { level: t(`runtimePatches.levels.${levelKey(d.level)}`), tag: d.tag, runtime: `${d.runtime} ${d.version}` })
  }
  if (type === 'runtime_patch' && d.event === 'migrated' && d.project_name) {
    return t('notifications.runtimePatchMigrated', { project: d.project_name, tag: d.tag || '' })
  }
  if (type === 'runtime_patch' && d.event === 'migration_failed' && d.project_name) {
    return t('notifications.runtimePatchMigrationFailed', { project: d.project_name, tag: d.tag || '' })
  }
  if (type === 'runtime_patch' && d.event === 'pin_dropped' && d.project_name) {
    return t('notifications.runtimePatchPinDropped', { project: d.project_name })
  }
  if (type === 'runtime_eol' && d.eol_at) {
    return t('notifications.runtimeEol', { runtime: `${d.runtime} ${d.version}`, date: d.eol_at })
  }

  // ── Promotions between environments (docs/environments.md#notifications) ──
  const promotion = PROMOTION_TITLES[type]
  if (promotion && d.project_name) {
    return t(promotion, { project: d.project_name, version: d.version ?? '', target: d.target_environment || 'production' })
  }

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
  // Runtime patches: the project(s) concerned, the Patches page of platform administrators
  if (['runtime_patch', 'runtime_eol', 'runtime_patch_feed'].includes(n.type)) {
    return pathLocation(n.data?.path)
  }
  // Promotions: the approvals inbox (one waits for the reader), else the project's environments
  if (isPromotionNotification(n)) {
    if (n.type === 'environment.promotion.pending_approval') return { path: '/approvals' }
    return n.data?.project_id ? { path: `/projects/${n.data.project_id}`, hash: '#environments' } : null
  }
  return null
}

import { describe, it, expect } from 'vitest'
import { fmtCpu, fmtMem, statusBadgeClass, redisStatusBadgeClass } from '@/utils/formatters'
import { describeCron } from '@/utils/cron'
import { notificationTitle, notificationAction } from '@/utils/notifications'
import { fakeT } from '../helpers'

describe('fmtCpu / fmtMem', () => {
  it.each([[0, '0m'], [null, '0m'], [250, '250m'], [999, '999m'], [1000, '1.0 core'], [2500, '2.5 core']])('fmtCpu(%j) = %j', (m, out) => {
    expect(fmtCpu(m)).toBe(out)
  })

  it.each([[0, '0 MB'], [undefined, '0 MB'], [512, '512 MB'], [1024, '1.0 GB'], [1536, '1.5 GB']])('fmtMem(%j) = %j', (mb, out) => {
    expect(fmtMem(mb)).toBe(out)
  })
})

describe('status badges', () => {
  it.each([
    ['ready', 'text-emerald-400'],
    ['installing', 'animate-pulse'],
    ['uninstalling', 'text-orange-400'],
    ['degraded', 'text-orange-400'],
    ['error', 'text-red-400'],
    ['not_installed', 'text-gray-400'],
    ['something-new', 'text-gray-400'],
  ])('component status %j uses %j', (status, cls) => {
    expect(statusBadgeClass(status)).toContain(cls)
    expect(statusBadgeClass(status)).toContain('inline-flex')
  })

  it('redis status has no degraded state', () => {
    expect(redisStatusBadgeClass('ready')).toContain('text-emerald-400')
    expect(redisStatusBadgeClass('degraded')).toContain('text-gray-400')
  })
})

describe('describeCron', () => {
  it.each([
    ['* * * * *', 'cron.everyMinute'],
    ['*/15 * * * *', 'cron.everyNMinutes {"n":"15"}'],
    ['0 */6 * * *', 'cron.everyNHours {"n":"6"}'],
    ['0 * * * *', 'cron.everyHour'],
    ['30 9 * * *', 'cron.everyDay {"time":"09:30"}'],
    ['0 8 * * 1-5', 'cron.weekdays {"time":"08:00"}'],
    ['0 10 * * 0,6', 'cron.weekends {"time":"10:00"}'],
    ['0 10 * * 6,0', 'cron.weekends {"time":"10:00"}'],
    ['5 7 * * 1', 'cron.everyWeekday {"day":"cron.dayMonday","time":"07:05"}'],
    ['0 0 15 * *', 'cron.monthlyDay {"day":"15","time":"00:00"}'],
  ])('%j -> %j', (expr, out) => {
    expect(describeCron(expr, fakeT)).toBe(out)
  })

  it('returns the expression when it has no simple description', () => {
    expect(describeCron('0 0 1 1 *', fakeT)).toBe('0 0 1 1 *')
    expect(describeCron('0 0 * * 1,3', fakeT)).toBe('0 0 * * 1,3')
    expect(describeCron('bad expr', fakeT)).toBe('bad expr')
    expect(describeCron('*/5 * * * *')).toBe('*/5 * * * *')
  })

  it('returns an empty string for an empty expression', () => {
    expect(describeCron('', fakeT)).toBe('')
    expect(describeCron(null, fakeT)).toBe('')
  })
})

describe('notificationTitle', () => {
  it.each([
    [{ type: 'resource_threshold', data: { resource_type: 'cpu', region_name: 'eu', percentage: 91 } }, 'notifications.cpuThreshold {"region":"eu","percentage":91}'],
    [{ type: 'resource_threshold', data: { resource_type: 'memory', region_name: 'eu', percentage: 80 } }, 'notifications.memoryThreshold {"region":"eu","percentage":80}'],
    [{ type: 'knative.serving.install_failed', data: { region_name: 'eu' } }, 'notifications.knativeInstallFailed {"region":"eu","component":"serving"}'],
    [{ type: 'knative.operator.installed', data: { region_name: 'eu', component: 'Operator' } }, 'notifications.knativeInstalled {"region":"eu","component":"Operator"}'],
    [{ type: 'sandbox.kata.uninstalled', data: { region_name: 'eu' } }, 'notifications.sandboxUninstalled {"region":"eu","runtime":"kata"}'],
    [{ type: 'redis.remove_failed', data: { region_name: 'eu' } }, 'notifications.redisRemoveFailed {"region":"eu"}'],
    [{ type: 'region.create_failed', data: {} }, 'notifications.regionCreateFailed'],
    [{ type: 'region.migration_completed', data: { region_name: 'eu' } }, 'notifications.regionMigrated {"region":"eu"}'],
    [{ type: 'build.failed', data: { project_name: 'api' } }, 'notifications.buildFailed {"project":"api","version":""}'],
    [{ type: 'deployment.ready', data: { project_name: 'api' } }, 'notifications.deploymentReady {"project":"api"}'],
    [{ type: 'demo.deploy_failed' }, 'notifications.demoSeedFailed'],
    [{ type: 'ticket.reply', data: { ticket_subject: 'Help' } }, 'notifications.ticketReply {"subject":"Help"}'],
  ])('%j', (n, out) => {
    expect(notificationTitle(n, fakeT)).toBe(out)
  })

  it('falls back to the stored title for unknown or unmapped types', () => {
    expect(notificationTitle({ type: 'something.else', title: 'Stored' }, fakeT)).toBe('Stored')
    expect(notificationTitle({ type: 'knative.serving.weird', title: 'Raw' }, fakeT)).toBe('Raw')
    expect(notificationTitle({ type: 'ticket.status', title: 'Closed' }, fakeT)).toBe('Closed')
    expect(notificationTitle({}, fakeT)).toBe('')
  })

  it('every mapped notification key exists in the en catalog', async () => {
    const { default: en } = await import('@/locales/en')
    const keys = new Set()
    const capture = (key) => { keys.add(key); return key }
    const samples = [
      'resource_threshold', 'knative.x.installed', 'knative.x.install_failed', 'knative.x.uninstalled', 'knative.x.uninstall_failed',
      'sandbox.x.installed', 'sandbox.x.install_failed', 'sandbox.x.uninstalled', 'sandbox.x.uninstall_failed',
      'redis.installed', 'redis.install_failed', 'redis.removed', 'redis.remove_failed',
      'region.created', 'region.create_failed', 'region.migrated', 'region.migrate_failed', 'region.detached', 'region.detach_failed',
      'build.completed', 'build.failed', 'deployment.ready', 'deployment.failed',
      'demo.seeded', 'demo.seed_failed', 'demo.removed', 'demo.remove_failed', 'ticket.created', 'ticket.reply',
    ]
    for (const type of samples) notificationTitle({ type, data: { resource_type: 'cpu' } }, capture)
    notificationTitle({ type: 'resource_threshold', data: { resource_type: 'memory' } }, capture)

    const missing = [...keys].filter(k => k.split('.').reduce((o, p) => o?.[p], en) === undefined)
    expect(missing).toEqual([])
    expect(keys.size).toBeGreaterThan(25)
  })
})

describe('notificationAction', () => {
  it('links ticket notifications to the support tab', () => {
    expect(notificationAction({ type: 'ticket.reply', data: { ticket_id: 12 } }))
      .toEqual({ path: '/settings', query: { tab: 'support-tickets', ticketId: 12 } })
    expect(notificationAction({ type: 'ticket.created', data: { ticket_id: 3, tenant_id: 't1' } }).query.tenantId).toBe('t1')
  })

  it('has no action for other notifications', () => {
    expect(notificationAction({ type: 'build.failed' })).toBeNull()
  })
})

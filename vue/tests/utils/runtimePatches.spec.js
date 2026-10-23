import { describe, expect, it } from 'vitest'
import { cveUrl, deadlinePassed, levelClasses, levelKey, levelRank, pathLocation, patchBadge, patchReason } from '@/utils/runtimePatches'
import { notificationAction, notificationTitle } from '@/utils/notifications'
import { fakeT } from '../helpers'

describe('runtime patch helpers', () => {
  it('orders the levels and maps them to i18n keys and colours', () => {
    expect(['zero-day', 'routine', 'critical', 'security'].sort((a, b) => levelRank(a) - levelRank(b)))
      .toEqual(['routine', 'security', 'critical', 'zero-day'])
    expect(levelRank('nonsense')).toBe(0)
    expect(levelKey('zero-day')).toBe('zeroDay')
    expect(levelKey('critical')).toBe('critical')
    expect(levelKey(undefined)).toBe('routine')
    expect(levelClasses('zero-day')).toContain('text-red-400')
    expect(levelClasses('security')).toContain('text-amber-400')
    expect(levelClasses('routine')).toContain('text-gray-400')
  })

  it('shows nothing for an up-to-date project or none at all', () => {
    expect(patchBadge(null)).toBeNull()
    expect(patchBadge({ state: 'up_to_date', level: null })).toBeNull()
  })

  it('a routine patch is a subtle badge, the others take the colour of their level', () => {
    expect(patchBadge({ state: 'patch_available', level: 'routine' })).toMatchObject({ kind: 'available', level: 'routine', subtle: true })
    const critical = patchBadge({ state: 'patch_available', level: 'critical' })
    expect(critical).toMatchObject({ kind: 'available', level: 'critical', subtle: false })
    expect(critical.classes).toContain('orange')
  })

  it('an overdue deadline is red whatever the level; a pin names its tag and warns when it is gone', () => {
    expect(patchBadge({ state: 'overdue', level: 'critical' })).toMatchObject({ kind: 'overdue', subtle: false })
    expect(patchBadge({ state: 'overdue', level: 'critical' }).classes).toContain('red')
    expect(patchBadge({ state: 'pinned', pin: { id: 'php-8.3-8.3.36-r1', tag: '8.3.36-r1', available: true } }))
      .toMatchObject({ kind: 'pinned', tag: '8.3.36-r1', warn: false })
    expect(patchBadge({ state: 'pinned', pin: { id: 'php-8.3-8.3.30-r1', tag: null, available: false } }))
      .toMatchObject({ kind: 'pinned', tag: 'php-8.3-8.3.30-r1', warn: true })
  })

  it('links CVEs, picks the reason of the locale and reads deadlines', () => {
    expect(cveUrl('CVE-2026-1234')).toBe('https://www.cve.org/CVERecord?id=CVE-2026-1234')
    const patch = { reason: 'Security update.', reasons: { en: 'Security update.', tr: 'Güvenlik güncellemesi.' } }
    expect(patchReason(patch, 'tr')).toBe('Güvenlik güncellemesi.')
    expect(patchReason({ reason: 'Only this.' }, 'tr')).toBe('Only this.')
    expect(patchReason(null, 'en')).toBe('')
    expect(deadlinePassed('2020-01-01T00:00:00Z')).toBe(true)
    expect(deadlinePassed('2999-01-01T00:00:00Z')).toBe(false)
    expect(deadlinePassed(null)).toBe(false)
  })

  it('turns a notification path into a router location', () => {
    expect(pathLocation('/settings?tab=patches')).toEqual({ path: '/settings', query: { tab: 'patches' } })
    expect(pathLocation('/projects/p1')).toEqual({ path: '/projects/p1' })
    expect(pathLocation('https://elsewhere.test')).toBeNull()
    expect(pathLocation(null)).toBeNull()
  })
})

describe('runtime patch notifications', () => {
  it('translates a new patch and an end-of-life reminder from their data', () => {
    const patch = { type: 'runtime_patch', title: 'stored', data: { event: 'patch', level: 'zero-day', runtime: 'php', version: '8.3', tag: '8.3.36-r1' } }
    expect(notificationTitle(patch, fakeT)).toContain('notifications.runtimePatch')
    expect(notificationTitle(patch, fakeT)).toContain('"tag":"8.3.36-r1"')
    expect(notificationTitle(patch, fakeT)).toContain('"runtime":"php 8.3"')

    const eol = { type: 'runtime_eol', title: 'stored', data: { runtime: 'nodejs', version: '20', eol_at: '2026-04-30', weeks: 4 } }
    expect(notificationTitle(eol, fakeT)).toContain('notifications.runtimeEol')
  })

  it('translates migrations and a dropped pin that name their project', () => {
    const failed = { type: 'runtime_patch', title: 'stored', data: { event: 'migration_failed', project_name: 'orders', tag: '8.3.36-r1' } }
    expect(notificationTitle(failed, fakeT)).toContain('notifications.runtimePatchMigrationFailed')
    expect(notificationTitle(failed, fakeT)).toContain('"project":"orders"')
    expect(notificationTitle({ type: 'runtime_patch', title: 'stored', data: { event: 'migrated', project_name: 'orders', tag: '8.3.36-r1' } }, fakeT)).toContain('notifications.runtimePatchMigrated')
    expect(notificationTitle({ type: 'runtime_patch', title: 'stored', data: { event: 'pin_dropped', project_name: 'orders' } }, fakeT)).toContain('notifications.runtimePatchPinDropped')
  })

  it('keeps the title the API wrote when the data does not say enough, and for the feed', () => {
    expect(notificationTitle({ type: 'runtime_patch', title: 'Patch could not be applied', data: { event: 'migration_failed' } }, fakeT)).toBe('Patch could not be applied')
    expect(notificationTitle({ type: 'runtime_patch_feed', title: 'Feed refused', data: {} }, fakeT)).toBe('Feed refused')
  })

  it('opens the project or the Patches page', () => {
    expect(notificationAction({ type: 'runtime_patch', data: { path: '/projects/p1' } })).toEqual({ path: '/projects/p1' })
    expect(notificationAction({ type: 'runtime_patch_feed', data: { path: '/settings?tab=patches' } })).toEqual({ path: '/settings', query: { tab: 'patches' } })
    expect(notificationAction({ type: 'runtime_eol', data: {} })).toBeNull()
  })
})

describe('promotion notifications', () => {
  it('titles each promotion event from its data and falls back to the stored title', () => {
    const data = { project_name: 'shop', version: '2', target_environment: 'production', project_id: 'p1' }
    expect(notificationTitle({ type: 'environment.promotion.pending_approval', data }, fakeT)).toContain('notifications.promotionPending')
    expect(notificationTitle({ type: 'environment.promotion.pending_approval', data }, fakeT)).toContain('"target":"production"')
    expect(notificationTitle({ type: 'environment.promotion.approved', data }, fakeT)).toContain('notifications.promotionApproved')
    expect(notificationTitle({ type: 'environment.promotion.rejected', data }, fakeT)).toContain('notifications.promotionRejected')
    expect(notificationTitle({ type: 'environment.promotion.deployed', data }, fakeT)).toContain('notifications.promotionDeployed')
    expect(notificationTitle({ type: 'environment.promotion.failed', data }, fakeT)).toContain('notifications.promotionFailed')
    expect(notificationTitle({ type: 'environment.promotion.failed', title: 'stored', data: {} }, fakeT)).toBe('stored')
  })

  it('opens the approvals inbox for an approver and the project environments otherwise', () => {
    expect(notificationAction({ type: 'environment.promotion.pending_approval', data: { project_id: 'p1' } })).toEqual({ path: '/approvals' })
    expect(notificationAction({ type: 'environment.promotion.deployed', data: { project_id: 'p1' } })).toEqual({ path: '/projects/p1', hash: '#environments' })
    expect(notificationAction({ type: 'environment.promotion.failed', data: {} })).toBeNull()
  })
})

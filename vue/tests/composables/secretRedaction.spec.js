import { describe, it, expect, vi } from 'vitest'

// Broadcasts carry env var names with masked values (docs/security.md); the console keeps the
// values it already loaded and reloads when the names changed.
vi.mock('@/api/projects', () => ({ projectsApi: { get: vi.fn() } }))
vi.mock('@/composables/useJobEvents', () => ({ useJobEvents: vi.fn() }))

import { mergeRedactedProject } from '@/composables/usePlatformSync'
import { filterParams, toIso } from '@/modules/audit/composables/useAuditLog'

const MASK = '••••••'

describe('mergeRedactedProject', () => {
  it('passes an unredacted project through', () => {
    const incoming = { id: 'p', config: { env_vars: { A: '1' } } }
    expect(mergeRedactedProject(incoming, null)).toEqual({ project: incoming, stale: false })
  })

  it('keeps the loaded values when the names match', () => {
    const local = { id: 'p', config: { env_vars: { A: 'real-a', B: 'real-b' } } }
    const incoming = { id: 'p', name: 'renamed', config: { env_vars: { B: MASK, A: MASK }, env_vars_redacted: true, cpu: '500m' } }

    const { project, stale } = mergeRedactedProject(incoming, local)
    expect(stale).toBe(false)
    expect(project.name).toBe('renamed')
    expect(project.config.cpu).toBe('500m')
    expect(project.config.env_vars).toEqual({ A: 'real-a', B: 'real-b' })
    expect(project.config).not.toHaveProperty('env_vars_redacted')
  })

  it('never takes masked values and asks for a reload when the names changed', () => {
    const local = { id: 'p', config: { env_vars: { A: 'real-a' } } }
    const incoming = { id: 'p', config: { env_vars: { A: MASK, NEW: MASK }, env_vars_redacted: true } }

    const { project, stale } = mergeRedactedProject(incoming, local)
    expect(stale).toBe(true)
    expect(JSON.stringify(project)).not.toContain(MASK)
  })

  it('treats a project created without env vars as current', () => {
    const incoming = { id: 'p', config: { env_vars: {}, env_vars_redacted: true } }
    expect(mergeRedactedProject(incoming, null).stale).toBe(false)
  })
})

describe('audit log filters', () => {
  it('drops empty filters and sends dates as ISO 8601', () => {
    const params = filterParams({ actor: '', action: 'project.*', result: null, from: '2026-10-01T08:30', to: '' })
    expect(params).toEqual({ action: 'project.*', from: new Date('2026-10-01T08:30').toISOString() })
  })

  it('ignores an unparsable date', () => {
    expect(toIso('not a date')).toBeUndefined()
    expect(toIso('')).toBeUndefined()
  })
})

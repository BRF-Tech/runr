import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { withPlugins } from '../helpers'

// Settings → Audit log (docs/security.md): filters, table, detail drawer, export, retention.
const auditApi = vi.hoisted(() => ({
  list: vi.fn(),
  get: vi.fn(),
  export: vi.fn(),
  settings: vi.fn(),
  updateSettings: vi.fn(),
}))
vi.mock('@/api/security', () => ({ auditApi, projectSecretsApi: {} }))

import AuditLogSettings from '@/modules/audit/components/AuditLogSettings.vue'
import { canViewAuditLog } from '@/modules/audit/plugin'
import { usePermissionsStore } from '@/stores/permissions'

const event = (overrides = {}) => ({
  id: 'e1',
  occurred_at: '2026-10-10T09:00:00Z',
  tenant_id: null,
  actor: { type: 'user', id: '1', name: 'Jane', email: 'jane@example.test', auth_method: 'session', impersonator: null },
  action: 'project.update',
  result: 'success',
  target: { type: 'project', id: 'p1', name: 'orders' },
  ip_address: '203.0.113.7',
  user_agent: 'Mozilla/5.0',
  request_id: 'req-1',
  http_method: 'PUT',
  route: 'api/projects/{project}',
  status_code: 200,
  metadata: { changes: [{ model: 'project', id: 'p1', name: 'orders', event: 'updated', fields: ['description'], before: { description: 'a' }, after: { description: 'b' } }], reason: null },
  ...overrides,
})

function page(data, meta = {}) {
  return { data: { data, meta: { current_page: 1, last_page: 1, total: data.length, per_page: 25, actions: ['auth.login', 'project.update'], can_export: true, ...meta } } }
}

async function mountLog() {
  const w = mount(AuditLogSettings, { global: withPlugins('en').global })
  await flushPromises()
  return w
}

beforeEach(() => {
  auditApi.list.mockResolvedValue(page([event()]))
  auditApi.settings.mockResolvedValue({ data: { retention_days: 365, max_days: 3650, forward_enabled: false, can_update: true } })
  auditApi.updateSettings.mockResolvedValue({ data: { retention_days: 90, max_days: 3650, forward_enabled: false, can_update: true } })
})

describe('AuditLogSettings', () => {
  it('lists events with actor, action, target and result', async () => {
    const w = await mountLog()
    const row = w.find('[data-testid="audit-row"]')
    expect(row.text()).toContain('Jane')
    expect(row.text()).toContain('project.update')
    expect(row.text()).toContain('project: orders')
    expect(row.text()).toContain('Success')
    expect(row.text()).toContain('203.0.113.7')
  })

  it('sends the filters to the API', async () => {
    const w = await mountLog()
    await w.find('[data-testid="audit-filter-actor"]').setValue('jane')
    await w.find('[data-testid="audit-filter-action"]').setValue('auth.login')
    await w.find('[data-testid="audit-filter-result"]').setValue('failure')
    await w.find('[data-testid="audit-filter-from"]').setValue('2026-10-01T00:00')
    await w.find('[data-testid="audit-filters"]').trigger('submit')
    await flushPromises()

    const params = auditApi.list.mock.calls.at(-1)[0]
    expect(params).toMatchObject({ actor: 'jane', action: 'auth.login', result: 'failure', page: 1 })
    expect(params.from).toBe(new Date('2026-10-01T00:00').toISOString())
    expect(params).not.toHaveProperty('target')

    await w.find('[data-testid="audit-filter-reset"]').trigger('click')
    await flushPromises()
    expect(auditApi.list.mock.calls.at(-1)[0]).toEqual({ page: 1, per_page: 25 })
  })

  it('opens the detail drawer with changes and closes it', async () => {
    const w = await mountLog()
    await w.find('[data-testid="audit-row"]').trigger('click')

    const drawer = w.find('[data-testid="audit-drawer"]')
    expect(drawer.exists()).toBe(true)
    expect(drawer.text()).toContain('req-1')
    expect(drawer.text()).toContain('api/projects/{project}')
    expect(w.find('[data-testid="audit-change"]').text()).toContain('description')

    await w.find('[data-testid="audit-drawer-close"]').trigger('click')
    expect(w.find('[data-testid="audit-drawer"]').exists()).toBe(false)
  })

  it('marks an impersonating platform admin', async () => {
    auditApi.list.mockResolvedValue(page([event({ actor: { type: 'user', id: '1', name: 'Root', impersonator: { id: '1', name: 'Root' } }, tenant_id: 't-1' })]))
    const w = await mountLog()
    expect(w.find('[data-testid="audit-row"]').text()).toContain('impersonating')
  })

  it('hides export without audit.export and shows the empty state', async () => {
    auditApi.list.mockResolvedValue(page([], { can_export: false }))
    const w = await mountLog()
    expect(w.find('[data-testid="audit-export-csv"]').exists()).toBe(false)
    expect(w.find('[data-testid="audit-empty"]').exists()).toBe(true)
  })

  it('exports CSV with the current filters', async () => {
    auditApi.export.mockResolvedValue({ data: new Blob(['a,b\n']), headers: { 'content-disposition': 'attachment; filename="runr-audit.csv"' } })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    const w = await mountLog()

    await w.find('[data-testid="audit-filter-action"]').setValue('project.update')
    await w.find('[data-testid="audit-export-csv"]').trigger('click')
    await flushPromises()

    expect(auditApi.export).toHaveBeenCalledWith({ action: 'project.update' }, 'csv')
    expect(URL.createObjectURL).toHaveBeenCalled()
  })

  it('saves the retention for platform admins', async () => {
    const w = await mountLog()
    await w.find('[data-testid="audit-retention-days"]').setValue(90)
    await w.find('[data-testid="audit-retention-save"]').trigger('click')
    await flushPromises()
    expect(auditApi.updateSettings).toHaveBeenCalledWith({ retention_days: 90 })
  })

  it('shows the retention read-only when it cannot be changed', async () => {
    auditApi.settings.mockResolvedValue({ data: { retention_days: 0, max_days: 3650, forward_enabled: true, can_update: false } })
    const w = await mountLog()
    const card = w.find('[data-testid="audit-retention"]')
    expect(card.text()).toContain('Events are kept forever.')
    expect(card.text()).toContain('SIEM forwarding is on')
    expect(w.find('[data-testid="audit-retention-save"]').exists()).toBe(false)
  })

  it('shows an error when the log cannot be read', async () => {
    auditApi.list.mockRejectedValue({ response: { status: 403 } })
    const w = await mountLog()
    expect(w.find('[data-testid="audit-error"]').exists()).toBe(true)
  })
})

describe('Audit log tab visibility', () => {
  it('follows the audit.view permission of GET /auth/permissions', () => {
    withPlugins('en') // fresh, active Pinia
    const permissions = usePermissionsStore()

    permissions.apply({ permissions: ['projects.view', 'env.view'], platform_admin: false })
    expect(canViewAuditLog()).toBe(false)

    permissions.apply({ permissions: ['audit.view'], platform_admin: false })
    expect(canViewAuditLog()).toBe(true)

    // Platform admins pass every check unless their API token is scoped
    permissions.apply({ permissions: [], platform_admin: true, token_scoped: false })
    expect(canViewAuditLog()).toBe(true)
    permissions.apply({ permissions: [], platform_admin: true, token_scoped: true })
    expect(canViewAuditLog()).toBe(false)
  })
})

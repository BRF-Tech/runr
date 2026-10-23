// Shared helpers of the organization export / import specs (docs/org-export.md; not a spec itself).
import { createI18n } from 'vue-i18n'
import en from '@/locales/en'
import orgEn from '@/modules/org-transfer/locales/en'

function merge(target, source) {
  const out = { ...target }
  for (const [key, value] of Object.entries(source)) {
    out[key] = value && typeof value === 'object' && !Array.isArray(value) && typeof out[key] === 'object'
      ? merge(out[key], value)
      : value
  }
  return out
}

/** vue-i18n with the English messages plus the module's (as main.js merges them). */
export function makeOrgTransferI18n() {
  return createI18n({
    legacy:         false,
    locale:         'en',
    fallbackLocale: 'en',
    messages:       { en: merge(en, orgEn) },
    missingWarn:    false,
    fallbackWarn:   false,
  })
}

export function transfer(extra = {}) {
  return {
    id: 'tr-1', kind: 'export', status: 'running', phase: 'projects', progress: 10, message: null,
    options: { projects: null, include_members: false, include_images: false, binding_data: [], include_secrets: false },
    estimate: null, destination: { type: 'download' }, package: null, upload: null, plan: null, report: null,
    created_by: { id: '1', name: 'Ada' }, created_at: '2026-10-21T12:00:00Z', updated_at: '2026-10-21T12:00:00Z',
    finished_at: null, expires_at: '2026-10-28T12:00:00Z',
    ...extra,
  }
}

export function pkg(extra = {}) {
  return {
    filename: 'runr-org-acme-20261021-120000.tar.zst', size_bytes: 5 * 1024 * 1024, sha256: 'ab'.repeat(32),
    schema_version: 1, runr_version: 'v0.5.11', source: { organization: 'Acme', slug: 'acme', exported_at: '2026-10-21T12:00:00Z' },
    downloadable: true,
    ...extra,
  }
}

export function plan(extra = {}) {
  return {
    generated_at: '2026-10-21T12:05:00Z',
    source: { organization: 'Acme', slug: 'acme', runr_version: 'v0.5.10', schema_version: 1, exported_at: '2026-10-21T12:00:00Z' },
    contents: { projects: 2, versions: 5, bindings: 1, binding_data: 1, images: 0, secrets: 'locked', members: 2, roles: 1, groups: 1, alert_channels: 1, alert_rules: 1, status_page: true, gateways: 0, extensions: [] },
    regions: [{ source_id: 'src-r1', source_name: 'eu-1', target_id: null, target_name: null, options: [{ id: 'r1', name: 'eu-west' }, { id: 'r2', name: 'tr-1' }] }],
    projects: [
      { source_id: 'p1', name: 'api', target_name: 'api-2', slug: 'api-2', service_type: 'faas', runtime: 'python', runtime_version: '3.12', source_region: 'eu-1', target_region_id: null, target_region: null, versions: 3, domains: ['api.example.com'], bindings: ['db'], collision: true, suggested_name: 'api-2', status: 'create',
        issues: [{ level: 'error', code: 'region_unmapped', message: 'Map region eu-1 to a region here.' }, { level: 'manual', code: 'dns', message: 'api.example.com needs DNS.' }] },
      { source_id: 'p2', name: 'web', target_name: 'web', slug: 'web', service_type: 'caas', runtime: null, runtime_version: null, source_region: 'eu-1', target_region_id: null, target_region: null, versions: 2, domains: [], bindings: [], collision: false, suggested_name: null, status: 'create', issues: [] },
    ],
    bindings: [{ source_id: 'b1', name: 'db', type: 'postgres', plan: 'shared', target_name: 'db', target_region_id: null, data: true, data_bytes: 1048576, status: 'create', issues: [] }],
    organization: { roles: [{ key: 'ops', name: 'Ops', status: 'create' }], groups: [{ name: 'SRE', status: 'exists' }], members: [{ email: 'a@example.com', role: 'developer', status: 'invite' }], alert_channels: [{ name: 'pager', type: 'webhook', status: 'create', secrets: 'missing' }], status_page: 'create' },
    manual_steps: [{ code: 'dns', message: 'Point these domains at the new region and verify them again.', items: ['api.example.com'] }],
    blocking: 1, warnings: 0, can_apply: false,
    ...extra,
  }
}

export function importTransfer(extra = {}) {
  return transfer({
    id: 'im-1', kind: 'import', status: 'planned', phase: 'plan', progress: 100, destination: null,
    package: pkg({ downloadable: false }), upload: { size_bytes: 5 * 1024 * 1024, uploaded_bytes: 5 * 1024 * 1024, chunk_size: 4, sha256: null },
    plan: plan(),
    ...extra,
  })
}

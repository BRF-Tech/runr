// Shared helpers of the supply-chain specs (not a spec itself).
import { createI18n } from 'vue-i18n'
import en from '@/locales/en'
import scEn from '@/modules/supply-chain/locales/en'

function merge(target, source) {
  const out = { ...target }
  for (const [key, value] of Object.entries(source)) {
    out[key] = value && typeof value === 'object' && !Array.isArray(value) && typeof out[key] === 'object'
      ? merge(out[key], value)
      : value
  }
  return out
}

/** vue-i18n with the English messages plus the supply-chain module's (as main.js merges them). */
export function makeScI18n() {
  return createI18n({
    legacy:         false,
    locale:         'en',
    fallbackLocale: 'en',
    messages:       { en: merge(en, scEn) },
    missingWarn:    false,
    fallbackWarn:   false,
  })
}

export const DIGEST = 'sha256:' + 'ab'.repeat(32)

export function report(extra = {}) {
  return {
    id: 'rep1', subject_type: 'version', version_id: 'v1', image_kind: 'built',
    image_ref: '10.43.0.5:5000/runr-builds/orders-api:v1', image_digest: DIGEST, status: 'completed',
    sbom_status: 'ok', scan_status: 'ok', signature_status: 'ok', attestation_status: 'ok', referrer_status: 'ok',
    counts: { critical: 2, high: 1, medium: 0, low: 3, unknown: 0 }, fixable: { critical: 1, high: 1 },
    findings_total: 3, packages: 42, scanned_at: '2026-10-10T08:15:00Z', signed_at: '2026-10-10T08:16:00Z',
    signature_key_fingerprint: 'f'.repeat(64), finished_at: '2026-10-10T08:16:00Z', error: null,
    ...extra,
  }
}

export const findings = [
  { id: 'CVE-2024-45491', severity: 'CRITICAL', package: 'libexpat1', installed: '2.5.0-1', fixed: '2.5.0-1+deb12u1', title: 'libexpat: Integer Overflow', url: 'https://nvd.nist.gov/vuln/detail/CVE-2024-45491' },
  { id: 'CVE-2023-45853', severity: 'CRITICAL', package: 'zlib1g', installed: '1:1.2.13', fixed: null, title: 'zlib: heap overflow', url: 'https://nvd.nist.gov/vuln/detail/CVE-2023-45853' },
  { id: 'GHSA-9wx4-h78v-vm56', severity: 'MEDIUM', package: 'requests', installed: '2.31.0', fixed: '2.32.0', title: 'Session verify', url: 'javascript:alert(1)' },
]

export function regionSettings(extra = {}) {
  return {
    knative_region_id: 'r1', sbom_enabled: true, scan_enabled: true, scan_external_images: false, rescan_interval_hours: 24,
    scanner_db_mode: 'online', scanner_db_source: null, scanner_db_status: 'none', scanner_db_updated_at: null, scanner_db_refreshed_at: null, scanner_db_message: null,
    gate_mode: 'off', gate_max_critical: 0, gate_max_high: null, gate_only_fixable: false, gate_require_scan: false,
    signing_enabled: false, verify_on_deploy: true, attest_enabled: true, signing_status: 'none', signing_public_key: null,
    signing_key_fingerprint: null, signing_key_created_at: null, signing_retired_keys: [], signing_message: null,
    admission_mode: 'off', admission_status: 'not_installed', admission_message: null, admission_require_sbom: false, admission_exempt_images: null,
    caas_policy: 'any', caas_allowed_registries: null, caas_trusted_keys: null, data_location: null,
    region: { id: 'r1', name: 'eu-1', has_registry: true, registry_mode: 'internal', project_namespace: 'fn' },
    scanner_db_repository: '10.43.0.5:5000/runr-supply-chain/trivy-db:2', scanner_db_default_source: 'mirror.gcr.io/aquasec/trivy-db:2',
    supply_chain_namespace: 'runr-supply-chain', can_manage: true,
    ...extra,
  }
}

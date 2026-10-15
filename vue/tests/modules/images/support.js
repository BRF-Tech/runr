// Shared helpers of the uploaded-images specs (not a spec itself).
import { createI18n } from 'vue-i18n'
import en from '@/locales/en'
import imagesEn from '@/modules/images/locales/en'

function merge(target, source) {
  const out = { ...target }
  for (const [key, value] of Object.entries(source)) {
    out[key] = value && typeof value === 'object' && !Array.isArray(value) && typeof out[key] === 'object'
      ? merge(out[key], value)
      : value
  }
  return out
}

/** vue-i18n with the English messages plus the images module's (as main.js merges them). */
export function makeImagesI18n() {
  return createI18n({
    legacy:         false,
    locale:         'en',
    fallbackLocale: 'en',
    messages:       { en: merge(en, imagesEn) },
    missingWarn:    false,
    fallbackWarn:   false,
  })
}

export const DIGEST = 'sha256:' + 'ab'.repeat(32)

export function image(extra = {}) {
  return {
    id: 'img1', name: 'web:1.0', repository: 'web', tag: '1.0', knative_region_id: 'r1', region_name: 'eu-1',
    image_ref: `10.43.0.5:5000/runr-uploads/platform/web@${DIGEST}`, tag_ref: '10.43.0.5:5000/runr-uploads/platform/web:1.0',
    digest: DIGEST, media_type: 'application/vnd.oci.image.index.v1+json', kind: 'image', source_format: 'oci',
    size_bytes: 52428800, archive_bytes: 60000000, platforms: ['linux/amd64', 'linux/arm64'], layers: 4,
    source_filename: 'web.tar', note: null,
    security: { status: 'completed', scan_status: 'ok', counts: { critical: 1, high: 2, medium: 0, low: 0, unknown: 0 } },
    created_at: '2026-10-11T08:00:00Z',
    ...extra,
  }
}

export function upload(extra = {}) {
  return {
    id: 'up1', name: 'web:1.0', repository: 'web', tag: '1.0', knative_region_id: 'r1', region_name: 'eu-1',
    filename: 'web.tar', format: 'auto', size_bytes: 10, sha256: null, uploaded_bytes: 0, chunk_size: 4,
    status: 'ready', message: null, image: null,
    ...extra,
  }
}

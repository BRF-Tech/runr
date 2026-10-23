<script setup>
// Branding editor. scope "platform": the platform branding (GET/PUT /api/admin/branding, platform
// admin); scope "tenant": the active tenant's override (GET/PUT /api/tenant/branding, tenant
// owner/admin). A field sent as null goes back to its default (platform: config / Helm value,
// tenant: the platform branding). Image fields take an upload, an https URL, a /storage/… path
// or a data:image/…;base64 URI.
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { brandingApi } from '../api/whiteLabel'
import { useBrandingStore } from '../stores/branding'
import { SHADES, expandPalette, isHexColor, normalizeHex, readableTextOn, toHex } from '../utils/color'
import { BRANDING_PRESETS, findPreset } from '../utils/presets'
import runrIconUrl from '@/assets/runr-icon.svg'
import runrWordmarkLightUrl from '@/assets/runr-wordmark-light.svg'
import runrWordmarkDarkUrl from '@/assets/runr-wordmark-dark.svg'
import { ImageUp, Trash2, RotateCcw, Plus, X, Loader2 } from 'lucide-vue-next'

const props = defineProps({
  scope: { type: String, default: 'platform', validator: v => ['platform', 'tenant'].includes(v) },
})

const { t } = useI18n()
const toast = useToast()
const store = useBrandingStore()

const IDENTITY_FIELDS = ['product_name', 'company_name', 'support_email', 'support_url']
const LOGIN_FIELDS    = ['login_headline', 'login_text']
const TEXT_FIELDS     = [...IDENTITY_FIELDS, ...LOGIN_FIELDS, 'email_from_name']
const COLOR_FIELDS    = ['primary_color', 'accent_color']
const LOGO_ASSETS     = [
  { kind: 'logo_light', field: 'logo_light_url', fallback: runrWordmarkLightUrl, surface: 'light' },
  { kind: 'logo_dark',  field: 'logo_dark_url',  fallback: runrWordmarkDarkUrl,  surface: 'dark' },
  { kind: 'logo_mark',  field: 'logo_mark_url',  fallback: runrIconUrl,          surface: 'light' },
  { kind: 'favicon',    field: 'favicon_url',    fallback: '/favicon.svg',       surface: 'light' },
]
const EMAIL_ASSET  = { kind: 'email_logo', field: 'email_logo_url', fallback: runrIconUrl, surface: 'light' }
const ASSETS       = [...LOGO_ASSETS, EMAIL_ASSET]

const isPlatform = computed(() => props.scope === 'platform')

const loading   = ref(true)
const saving    = ref(false)
const uploading = ref(null)
const error     = ref(null)
const errors    = ref({})
const enabled   = ref(true)                // tenant scope: platform allows tenant branding
const resolved  = ref(null)                // what the API resolves (effective branding)
const defaults  = ref({})                  // platform scope: config defaults
const multiTenant = ref(false)
const preset    = ref('')

const form = reactive({
  ...Object.fromEntries(TEXT_FIELDS.map(f => [f, null])),
  primary_color: null,
  accent_color: null,
  primary_scale: null,                     // null = expand from primary_color; { shade: '#rrggbb' | '' }
  ...Object.fromEntries(ASSETS.map(a => [a.field, null])),
  footer_links: null,
  powered_by: null,
  tenant_overrides_enabled: false,
})

function scaleForm(scale) {
  if (!scale || typeof scale !== 'object') return null
  const out = Object.fromEntries(SHADES.map(s => [s, toHex(scale[s]) ?? '']))
  return Object.values(out).some(Boolean) ? out : null
}

function fill(data) {
  resolved.value = data.branding ?? null
  const stored = data.stored ?? {}
  for (const key of [...TEXT_FIELDS, ...COLOR_FIELDS, ...ASSETS.map(a => a.field)]) {
    form[key] = stored[key] ?? null
  }
  form.primary_scale = scaleForm(stored.primary_scale)
  form.footer_links = Array.isArray(stored.footer_links) ? stored.footer_links.map(l => ({ ...l })) : null
  form.powered_by = stored.powered_by ?? null
  if (isPlatform.value) {
    defaults.value = data.defaults ?? {}
    form.tenant_overrides_enabled = !!data.tenant_overrides_enabled
    multiTenant.value = !!data.branding?.features?.multi_tenant
  } else {
    enabled.value = data.enabled !== false
  }
  preset.value = ''
}

// An uploaded or removed asset: only its field follows the answer, the texts and colours being
// edited (not saved yet) stay
function fillAsset(data, kind) {
  resolved.value = data.branding ?? resolved.value
  const asset = ASSETS.find(a => a.kind === kind)
  if (asset) form[asset.field] = (data.stored ?? {})[asset.field] ?? null
}

async function load() {
  loading.value = true
  error.value = null
  try {
    const { data } = isPlatform.value ? await brandingApi.adminGet() : await brandingApi.tenantGet()
    fill(data)
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** Effective value (stored override, else default / resolved). */
function effective(key) {
  if (form[key] !== null && form[key] !== undefined && form[key] !== '') return form[key]
  if (isPlatform.value && defaults.value[key] !== undefined && defaults.value[key] !== null) return defaults.value[key]
  return resolved.value?.[key] ?? null
}

function placeholder(key) {
  const v = isPlatform.value ? defaults.value[key] : resolved.value?.[key]
  return typeof v === 'string' && !v.startsWith('data:') ? v : ''
}

function reset(key) {
  form[key] = null
}

// ── Colours ─────────────────────────────────────────────────────────────────
const baseColor   = computed(() => normalizeHex(effective('primary_color')) || '#f97316')
const accentColor = computed(() => normalizeHex(effective('accent_color')) || '#e63946')

/** shade → "#rrggbb" actually used (override, else expanded from the base colour). */
const effectiveScale = computed(() => {
  const expanded = expandPalette(baseColor.value)
  return Object.fromEntries(SHADES.map(s => {
    const override = form.primary_scale?.[s]
    return [s, (override && isHexColor(override) ? normalizeHex(override) : null) ?? toHex(expanded[s])]
  }))
})

function setShade(shade, value) {
  const scale = form.primary_scale ? { ...form.primary_scale } : Object.fromEntries(SHADES.map(s => [s, '']))
  scale[shade] = value
  form.primary_scale = Object.values(scale).some(Boolean) ? scale : null
}

function applyPreset(key) {
  const p = findPreset(key)
  if (!p) return
  form.primary_color = p.primary_color
  form.accent_color = p.accent_color
  form.primary_scale = { ...p.primary_scale }
}

// ── Footer links ────────────────────────────────────────────────────────────
function addLink() {
  form.footer_links = [...(form.footer_links ?? resolved.value?.footer_links ?? []).map(l => ({ ...l })), { label: '', url: '' }]
}
function removeLink(i) {
  const links = [...(form.footer_links ?? [])]
  links.splice(i, 1)
  form.footer_links = links
}

// ── Preview ─────────────────────────────────────────────────────────────────
const previewLogo    = computed(() => effective('logo_light_url'))
const previewDark    = computed(() => effective('logo_dark_url') || effective('logo_light_url'))
const previewMark    = computed(() => effective('logo_mark_url') || runrIconUrl)
const previewLinks   = computed(() => form.footer_links ?? resolved.value?.footer_links ?? [])
const previewPowered = computed(() => form.powered_by ?? (isPlatform.value ? defaults.value.powered_by : resolved.value?.powered_by) ?? true)
const previewName    = computed(() => effective('product_name') || 'RunR')
const previewCompany = computed(() => effective('company_name') || 'BRF Teknoloji')
const previewDefault = computed(() => previewName.value === 'RunR')

/** PUT payload: '' → null (default); colours must be hex. */
function payload() {
  const out = {}
  for (const key of TEXT_FIELDS) {
    const v = form[key]
    out[key] = v === null || v === undefined || String(v).trim() === '' ? null : String(v).trim()
  }
  for (const key of COLOR_FIELDS) {
    out[key] = form[key] && isHexColor(form[key]) ? normalizeHex(form[key]) : null
  }
  const overridden = form.primary_scale && SHADES.some(s => form.primary_scale[s] && isHexColor(form.primary_scale[s]))
  out.primary_scale = overridden ? { ...effectiveScale.value } : null
  for (const { field } of ASSETS) {
    const v = form[field]
    out[field] = v === null || v === undefined || String(v).trim() === '' ? null : String(v).trim()
  }
  out.footer_links = form.footer_links === null
    ? null
    : form.footer_links.filter(l => String(l.label ?? '').trim() && String(l.url ?? '').trim())
        .map(l => ({ label: String(l.label).trim(), url: String(l.url).trim() }))
  if (isPlatform.value) {
    out.powered_by = form.powered_by
    if (multiTenant.value) out.tenant_overrides_enabled = !!form.tenant_overrides_enabled
  }
  return out
}

defineExpose({ payload, applyPreset })

function refreshStore(data) {
  // Keep the console in sync when the edited branding is the one being shown
  if (data?.branding) {
    const showingTenant = !!store.tenantSlug
    if ((isPlatform.value && !showingTenant) || (!isPlatform.value && showingTenant)) store.set(data.branding)
  }
}

async function save() {
  saving.value = true
  error.value = null
  errors.value = {}
  try {
    const body = payload()
    const { data } = isPlatform.value ? await brandingApi.adminUpdate(body) : await brandingApi.tenantUpdate(body)
    fill(data)
    refreshStore(data)
    toast.success(t('whiteLabel.branding.saved'))
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

async function upload(kind, event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  if (file.size > 1024 * 1024) {
    toast.error(t('whiteLabel.branding.fileTooLarge'))
    return
  }
  uploading.value = kind
  try {
    const { data } = isPlatform.value ? await brandingApi.adminUpload(kind, file) : await brandingApi.tenantUpload(kind, file)
    fillAsset(data, kind)
    refreshStore(data)
    toast.success(t('whiteLabel.branding.uploaded'))
  } catch (e) {
    toast.error(e.response?.data?.errors?.file?.[0] ?? e.response?.data?.message ?? t('common.error'))
  } finally {
    uploading.value = null
  }
}

async function removeAsset(kind) {
  uploading.value = kind
  try {
    const { data } = isPlatform.value ? await brandingApi.adminRemove(kind) : await brandingApi.tenantRemove(kind)
    fillAsset(data, kind)
    refreshStore(data)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    uploading.value = null
  }
}

function fieldError(key) {
  return errors.value?.[key]?.[0] ?? null
}

/** Shortened display of a data: URI in the URL field's placeholder area. */
function isDataUri(v) {
  return typeof v === 'string' && v.startsWith('data:')
}
</script>

<template>
  <div>
    <div v-if="loading" class="py-16 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="!isPlatform && !enabled" class="card text-sm text-gray-400" data-test="tenant-branding-disabled">
      {{ t('whiteLabel.branding.tenantDisabled') }}
    </div>

    <div v-else class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]" data-test="branding-form">
      <form class="space-y-6" @submit.prevent="save">
        <!-- Identity + login page -->
        <section v-for="group in [{ key: 'identity', fields: IDENTITY_FIELDS }, { key: 'loginPage', fields: LOGIN_FIELDS }]" :key="group.key" class="card space-y-4">
          <h4 class="text-sm font-semibold text-gray-100">{{ t(`whiteLabel.branding.${group.key}`) }}</h4>
          <div v-for="key in group.fields" :key="key">
            <div class="flex items-center justify-between">
              <label class="label" :for="`branding-${key}`">{{ t(`whiteLabel.branding.field_${key}`) }}</label>
              <button v-if="form[key] !== null" type="button" class="text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
                :data-test="`reset-${key}`" @click="reset(key)">
                <RotateCcw class="w-3 h-3" />{{ t('whiteLabel.branding.reset') }}
              </button>
            </div>
            <textarea v-if="key === 'login_text'" :id="`branding-${key}`" v-model="form[key]" rows="3" class="input"
              :placeholder="placeholder(key)" :data-test="`field-${key}`" />
            <input v-else :id="`branding-${key}`" v-model="form[key]" class="input"
              :type="key === 'support_email' ? 'email' : key === 'support_url' ? 'url' : 'text'"
              :placeholder="placeholder(key)" :data-test="`field-${key}`" />
            <p v-if="fieldError(key)" class="text-xs text-red-400 mt-1">{{ fieldError(key) }}</p>
          </div>
        </section>

        <!-- Colours -->
        <section class="card space-y-4" data-test="branding-colors">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.branding.colors') }}</h4>
            <div class="flex items-center gap-2">
              <label class="text-xs text-gray-400" for="branding-preset">{{ t('whiteLabel.branding.presets') }}</label>
              <select id="branding-preset" v-model="preset" class="input h-8 text-xs w-44" data-test="branding-preset"
                @change="applyPreset($event.target.value)">
                <option value="">{{ t('whiteLabel.branding.presetPick') }}</option>
                <option v-for="p in BRANDING_PRESETS" :key="p.key" :value="p.key">{{ t(`whiteLabel.branding.preset_${p.key}`) }}</option>
              </select>
            </div>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div v-for="key in COLOR_FIELDS" :key="key">
              <div class="flex items-center justify-between">
                <label class="label">{{ t(`whiteLabel.branding.field_${key}`) }}</label>
                <button v-if="form[key] !== null" type="button" class="text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
                  :data-test="`reset-${key}`" @click="reset(key)">
                  <RotateCcw class="w-3 h-3" />{{ t('whiteLabel.branding.reset') }}
                </button>
              </div>
              <div class="flex items-center gap-2">
                <input type="color" class="h-9 w-12 rounded-sm border border-gray-700 bg-gray-800 cursor-pointer"
                  :value="normalizeHex(effective(key)) || '#000000'" :data-test="`picker-${key}`"
                  @input="form[key] = $event.target.value" />
                <input v-model="form[key]" class="input font-mono" maxlength="7" :placeholder="placeholder(key)" :data-test="`field-${key}`" />
              </div>
              <p v-if="form[key] && !isHexColor(form[key])" class="text-xs text-red-400 mt-1">{{ t('whiteLabel.branding.invalidColor') }}</p>
              <p v-else-if="fieldError(key)" class="text-xs text-red-400 mt-1">{{ fieldError(key) }}</p>
              <p v-if="key === 'primary_color'" class="text-xs text-gray-400 mt-1">{{ t('whiteLabel.branding.baseHint') }}</p>
            </div>
          </div>

          <!-- Scale -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <span class="label mb-0">{{ t('whiteLabel.branding.scale') }}</span>
              <button v-if="form.primary_scale" type="button" class="text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
                data-test="reset-primary_scale" @click="form.primary_scale = null">
                <RotateCcw class="w-3 h-3" />{{ t('whiteLabel.branding.scaleReset') }}
              </button>
            </div>
            <div class="grid grid-cols-7 gap-2" data-test="branding-scale">
              <div v-for="shade in SHADES" :key="shade" class="flex flex-col items-center gap-1">
                <label class="relative w-full h-10 rounded-md border cursor-pointer"
                  :class="form.primary_scale?.[shade] ? 'border-gray-300' : 'border-gray-700'"
                  :style="{ background: effectiveScale[shade] }" :title="effectiveScale[shade]" :data-test="`swatch-${shade}`">
                  <input type="color" class="absolute inset-0 opacity-0 cursor-pointer" :value="effectiveScale[shade]"
                    :data-test="`shade-${shade}`" @input="setShade(shade, $event.target.value)" />
                </label>
                <span class="text-[10px] text-gray-400 font-mono">{{ shade }}</span>
                <button v-if="form.primary_scale?.[shade]" type="button" class="text-[10px] text-gray-500 hover:text-gray-100"
                  :data-test="`reset-shade-${shade}`" @click="setShade(shade, '')">{{ t('whiteLabel.branding.reset') }}</button>
              </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">{{ t('whiteLabel.branding.scaleHint') }}</p>
          </div>
        </section>

        <!-- Logos -->
        <section class="card space-y-4">
          <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.branding.assets') }}</h4>
          <p class="text-xs text-gray-400">{{ t('whiteLabel.branding.assetsHint') }}</p>
          <div v-for="asset in LOGO_ASSETS" :key="asset.kind" class="flex flex-wrap items-center gap-3">
            <div class="w-28 h-14 rounded-lg border border-gray-700 flex items-center justify-center overflow-hidden"
              :class="asset.surface === 'dark' ? 'bg-gray-950' : 'bg-white'">
              <img :src="effective(asset.field) || asset.fallback" alt="" class="max-h-12 max-w-26 object-contain" :data-test="`asset-preview-${asset.kind}`" />
            </div>
            <div class="flex-1 min-w-48">
              <p class="text-sm text-gray-100">
                {{ t(`whiteLabel.branding.asset_${asset.kind}`) }}
                <span v-if="!effective(asset.field)" class="text-xs text-gray-500">— {{ t('whiteLabel.branding.noImage') }}</span>
              </p>
              <input v-model="form[asset.field]" type="text" class="input mt-1 font-mono text-xs" :placeholder="t('whiteLabel.branding.assetUrlPlaceholder')"
                :data-test="`field-${asset.field}`" />
              <p v-if="isDataUri(form[asset.field])" class="text-[10px] text-gray-500 mt-1">{{ t('whiteLabel.branding.dataUri') }}</p>
              <p v-if="fieldError(asset.field)" class="text-xs text-red-400 mt-1">{{ fieldError(asset.field) }}</p>
            </div>
            <label class="btn-secondary cursor-pointer" :data-test="`upload-${asset.kind}`">
              <Loader2 v-if="uploading === asset.kind" class="w-4 h-4 animate-spin" />
              <ImageUp v-else class="w-4 h-4" />
              {{ t('whiteLabel.branding.upload') }}
              <input type="file" class="hidden" accept=".png,.jpg,.jpeg,.webp,.svg,.ico,image/png,image/jpeg,image/webp,image/svg+xml,image/x-icon"
                @change="upload(asset.kind, $event)" />
            </label>
            <button v-if="resolved?.[asset.field] || form[asset.field]" type="button" class="btn-secondary"
              :disabled="uploading === asset.kind" :data-test="`remove-${asset.kind}`" @click="removeAsset(asset.kind)">
              <Trash2 class="w-4 h-4" />{{ t('whiteLabel.branding.remove') }}
            </button>
          </div>
        </section>

        <!-- E-mail -->
        <section class="card space-y-4" data-test="branding-email">
          <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.branding.email') }}</h4>
          <div>
            <div class="flex items-center justify-between">
              <label class="label" for="branding-email_from_name">{{ t('whiteLabel.branding.field_email_from_name') }}</label>
              <button v-if="form.email_from_name !== null" type="button" class="text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
                data-test="reset-email_from_name" @click="reset('email_from_name')">
                <RotateCcw class="w-3 h-3" />{{ t('whiteLabel.branding.reset') }}
              </button>
            </div>
            <input id="branding-email_from_name" v-model="form.email_from_name" class="input" :placeholder="placeholder('email_from_name') || previewName"
              data-test="field-email_from_name" />
          </div>
          <div class="flex flex-wrap items-center gap-3">
            <div class="w-28 h-14 rounded-lg border border-gray-700 flex items-center justify-center overflow-hidden bg-white">
              <img :src="effective(EMAIL_ASSET.field) || EMAIL_ASSET.fallback" alt="" class="max-h-12 max-w-26 object-contain" data-test="asset-preview-email_logo" />
            </div>
            <div class="flex-1 min-w-48">
              <p class="text-sm text-gray-100">{{ t('whiteLabel.branding.asset_email_logo') }}</p>
              <input v-model="form[EMAIL_ASSET.field]" type="text" class="input mt-1 font-mono text-xs" :placeholder="t('whiteLabel.branding.assetUrlPlaceholder')"
                data-test="field-email_logo_url" />
            </div>
            <label class="btn-secondary cursor-pointer" data-test="upload-email_logo">
              <Loader2 v-if="uploading === 'email_logo'" class="w-4 h-4 animate-spin" />
              <ImageUp v-else class="w-4 h-4" />
              {{ t('whiteLabel.branding.upload') }}
              <input type="file" class="hidden" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                @change="upload('email_logo', $event)" />
            </label>
            <button v-if="resolved?.email_logo_url || form.email_logo_url" type="button" class="btn-secondary"
              :disabled="uploading === 'email_logo'" data-test="remove-email_logo" @click="removeAsset('email_logo')">
              <Trash2 class="w-4 h-4" />{{ t('whiteLabel.branding.remove') }}
            </button>
          </div>
          <p class="text-xs text-gray-400">{{ t('whiteLabel.branding.emailHint') }}</p>
        </section>

        <!-- Footer links -->
        <section class="card space-y-3">
          <div class="flex items-center justify-between">
            <h4 class="text-sm font-semibold text-gray-100">{{ t('whiteLabel.branding.footerLinks') }}</h4>
            <div class="flex items-center gap-3">
              <button v-if="form.footer_links !== null" type="button" class="text-xs text-gray-400 hover:text-gray-100 flex items-center gap-1"
                data-test="reset-footer_links" @click="reset('footer_links')">
                <RotateCcw class="w-3 h-3" />{{ t('whiteLabel.branding.reset') }}
              </button>
              <button type="button" class="text-xs text-gray-300 hover:text-gray-100 flex items-center gap-1" data-test="add-link" @click="addLink">
                <Plus class="w-3 h-3" />{{ t('whiteLabel.branding.addLink') }}
              </button>
            </div>
          </div>
          <p v-if="form.footer_links === null" class="text-xs text-gray-400">{{ t('whiteLabel.branding.footerDefault') }}</p>
          <div v-for="(link, i) in form.footer_links ?? []" :key="i" class="flex gap-2">
            <input v-model="link.label" class="input" :placeholder="t('whiteLabel.branding.linkLabel')" />
            <input v-model="link.url" type="url" class="input" placeholder="https://" />
            <button type="button" class="btn-secondary px-2" :title="t('whiteLabel.branding.remove')" @click="removeLink(i)">
              <X class="w-4 h-4" />
            </button>
          </div>
        </section>

        <!-- Platform-only switches -->
        <section v-if="isPlatform" class="card space-y-3">
          <label class="flex items-center justify-between gap-4 cursor-pointer">
            <span>
              <span class="block text-sm text-gray-100">{{ t('whiteLabel.branding.poweredBy') }}</span>
              <span class="block text-xs text-gray-400">{{ t('whiteLabel.branding.poweredByHint') }}</span>
            </span>
            <input type="checkbox" :checked="previewPowered" data-test="field-powered_by" @change="form.powered_by = $event.target.checked" />
          </label>
          <label v-if="multiTenant" class="flex items-center justify-between gap-4 cursor-pointer">
            <span>
              <span class="block text-sm text-gray-100">{{ t('whiteLabel.branding.tenantOverrides') }}</span>
              <span class="block text-xs text-gray-400">{{ t('whiteLabel.branding.tenantOverridesHint') }}</span>
            </span>
            <input v-model="form.tenant_overrides_enabled" type="checkbox" data-test="field-tenant_overrides_enabled" />
          </label>
        </section>

        <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
        <div class="flex justify-end">
          <button type="submit" class="btn-primary" :disabled="saving" data-test="branding-save">
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </form>

      <!-- Live preview -->
      <aside class="space-y-3" data-test="branding-preview">
        <p class="label">{{ t('whiteLabel.branding.preview') }}</p>
        <div class="rounded-xl overflow-hidden border border-gray-800">
          <div class="px-4 py-3 flex items-center gap-3" style="background:#030712">
            <img v-if="previewDark" :src="previewDark" alt="" class="h-7 max-w-32 object-contain" />
            <template v-else>
              <img :src="previewMark" alt="" class="h-7 w-7 object-contain" />
              <span class="text-sm font-bold text-white truncate">
                <template v-if="previewDefault">Run<span :style="{ color: accentColor }">R</span></template>
                <template v-else>{{ previewName }}</template>
              </span>
            </template>
          </div>
          <div class="bg-white px-5 py-5 space-y-3">
            <img v-if="previewLogo" :src="previewLogo" alt="" class="h-8 max-w-40 object-contain" />
            <p class="text-lg font-bold" style="color:#0f172a">{{ effective('login_headline') || t('whiteLabel.branding.previewHeadline') }}</p>
            <p v-if="effective('login_text')" class="text-xs" style="color:#64748b">{{ effective('login_text') }}</p>
            <div class="h-8 rounded-md" style="background:#f8fafc;border:1.5px solid #e2e8f0" />
            <button type="button" class="w-full py-2 rounded-md text-sm font-semibold" data-test="preview-button"
              :style="{ background: effectiveScale['600'], color: readableTextOn(effectiveScale['600']) }">{{ t('auth.submit') }}</button>
            <p class="text-xs font-semibold" :style="{ color: effectiveScale['500'] }">{{ t('whiteLabel.branding.previewLink') }}</p>
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] pt-2 border-t" style="border-color:#e2e8f0;color:#64748b">
              <span v-for="(link, i) in previewLinks" :key="i">{{ link.label }}</span>
              <span v-if="previewDefault">{{ t('whiteLabel.login.byCompany', { product: previewName, company: previewCompany }) }}</span>
              <template v-else>
                <span>{{ t('whiteLabel.login.copyright', { year: new Date().getFullYear(), company: previewCompany }) }}</span>
                <span v-if="previewPowered">{{ t('whiteLabel.login.poweredBy') }}</span>
              </template>
            </div>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>

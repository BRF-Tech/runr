<script setup>
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { AlertTriangle, MapPin } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { sandboxAdminApi } from '../../api/sandboxes'
import { apiError } from '../../utils'
import TermText from '@/components/TermText.vue'

const emit = defineEmits(['loaded'])
const { t } = useI18n()
const toast = useToast()

const regions = ref([])
const forms   = ref({})
const saving  = ref({})
const loading = ref(false)
const error   = ref(null)

function installedOf(r) {
  return (r.installed_classes ?? []).filter(c => c !== 'runc')
}

function toForm(r) {
  const installed = installedOf(r)
  const allowed   = Array.isArray(r.allowed_classes) ? r.allowed_classes : installed
  return {
    enabled:             r.enabled !== false,
    allowed:             installed.filter(c => allowed.includes(c)),
    default_class:       r.default_class ?? '',
    allow_runc:          !!r.allow_runc,
    default_ttl_seconds: r.default_ttl_seconds ?? null,
    max_ttl_seconds:     r.max_ttl_seconds ?? null,
    max_cpu_m:           r.max_cpu_m ?? null,
    max_memory_mb:       r.max_memory_mb ?? null,
    max_disk_mb:         r.max_disk_mb ?? null,
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await sandboxAdminApi.regions()
    regions.value = data?.data ?? []
    forms.value   = Object.fromEntries(regions.value.map(r => [r.region.id, toForm(r)]))
    error.value   = null
    emit('loaded', regions.value)
  } catch (e) {
    error.value = apiError(e, t('sandboxes.settings.regions.saveFailed'))
  } finally {
    loading.value = false
  }
}

/** PUT body of a region's form: every installed class allowed → null ("all installed"). */
function payloadOf(r) {
  const f = forms.value[r.region.id]
  const installed = installedOf(r)
  const allAllowed = installed.every(c => f.allowed.includes(c))
  const num = (v) => (v === '' || v === null || v === undefined) ? null : Number(v)
  return {
    enabled:             !!f.enabled,
    allowed_classes:     allAllowed ? null : [...f.allowed],
    default_class:       f.default_class || null,
    allow_runc:          !!f.allow_runc,
    default_ttl_seconds: num(f.default_ttl_seconds),
    max_ttl_seconds:     num(f.max_ttl_seconds),
    max_cpu_m:           num(f.max_cpu_m),
    max_memory_mb:       num(f.max_memory_mb),
    max_disk_mb:         num(f.max_disk_mb),
  }
}

async function save(r) {
  saving.value = { ...saving.value, [r.region.id]: true }
  try {
    const { data } = await sandboxAdminApi.updateRegion(r.region.id, payloadOf(r))
    const updated = data?.data
    if (updated) {
      const idx = regions.value.findIndex(x => x.region.id === r.region.id)
      if (idx !== -1) regions.value[idx] = updated
      forms.value[r.region.id] = toForm(updated)
    }
    toast.success(t('sandboxes.settings.regions.saved', { region: r.region.name }))
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.settings.regions.saveFailed')))
  } finally {
    saving.value = { ...saving.value, [r.region.id]: false }
  }
}

function defaultOptions(r) {
  const f = forms.value[r.region.id]
  const list = installedOf(r).filter(c => f.allowed.includes(c))
  if (f.allow_runc) list.push('runc')
  return list
}

onMounted(load)
defineExpose({ load })
</script>

<template>
  <section data-section="regions">
    <h4 class="text-sm font-semibold text-gray-100">{{ t('sandboxes.settings.regions.title') }}</h4>
    <p class="text-xs text-gray-400 mb-3">{{ t('sandboxes.settings.regions.subtitle') }}</p>

    <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>
    <p v-if="!regions.length && !loading" class="text-sm text-gray-400">{{ t('sandboxes.settings.regions.empty') }}</p>

    <div class="space-y-3">
      <div v-for="r in regions" :key="r.region.id" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4" :data-region-card="r.region.id">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
          <div class="flex items-center gap-2">
            <MapPin class="w-4 h-4 text-gray-400" />
            <span class="text-sm font-semibold text-gray-100">{{ r.region.name }}</span>
            <span class="text-[11px] font-mono text-gray-500">
              {{ t('sandboxes.settings.regions.effective', { classes: (r.effective_classes ?? []).join(', ') || '—' }) }}
              · {{ t('sandboxes.settings.regions.effectiveDefault', { cls: r.effective_default_class || '—' }) }}
            </span>
          </div>
          <label class="flex items-center gap-2 text-xs text-gray-300">
            <input v-model="forms[r.region.id].enabled" type="checkbox" name="enabled" :disabled="!r.can_manage" />
            {{ t('sandboxes.settings.regions.enabled') }}
          </label>
        </div>

        <p v-if="!r.can_manage" class="text-[11px] text-gray-500 mb-3">{{ t('sandboxes.settings.regions.readOnly') }}</p>

        <div class="grid md:grid-cols-2 gap-4">
          <div>
            <p class="label">{{ t('sandboxes.settings.regions.allowedClasses') }}</p>
            <p v-if="!installedOf(r).length" class="text-xs text-amber-400">{{ t('sandboxes.settings.regions.noClasses') }}</p>
            <div class="flex flex-wrap gap-3">
              <label v-for="c in installedOf(r)" :key="c" class="flex items-center gap-1.5 text-xs font-mono text-gray-300">
                <input v-model="forms[r.region.id].allowed" type="checkbox" :value="c" :name="`class-${c}`" :disabled="!r.can_manage" />{{ c }}
              </label>
            </div>

            <label class="flex items-center gap-2 text-xs text-gray-300 mt-3">
              <input v-model="forms[r.region.id].allow_runc" type="checkbox" name="allow_runc" :disabled="!r.can_manage" />
              {{ t('sandboxes.settings.regions.allowRunc') }}
            </label>
            <p v-if="forms[r.region.id].allow_runc" class="flex items-start gap-1.5 text-[11px] text-amber-400 mt-1" data-runc-warning>
              <AlertTriangle class="w-3.5 h-3.5 shrink-0" />{{ t('sandboxes.settings.regions.runcWarning') }}
            </p>

            <label class="label mt-3">{{ t('sandboxes.settings.regions.defaultClass') }}</label>
            <select v-model="forms[r.region.id].default_class" class="input" name="default_class" :disabled="!r.can_manage">
              <option value="">{{ t('sandboxes.settings.regions.strongest') }}</option>
              <option v-for="c in defaultOptions(r)" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label">{{ t('sandboxes.settings.regions.defaultTtl') }}</label>
              <input v-model.number="forms[r.region.id].default_ttl_seconds" class="input font-mono" type="number" min="1" name="default_ttl_seconds" :disabled="!r.can_manage" />
            </div>
            <div>
              <label class="label">{{ t('sandboxes.settings.regions.maxTtl') }}</label>
              <input v-model.number="forms[r.region.id].max_ttl_seconds" class="input font-mono" type="number" min="1" name="max_ttl_seconds" :disabled="!r.can_manage" />
            </div>
            <div>
              <label class="label">{{ t('sandboxes.settings.regions.maxCpu') }}</label>
              <input v-model.number="forms[r.region.id].max_cpu_m" class="input font-mono" type="number" min="1" name="max_cpu_m" :disabled="!r.can_manage" />
            </div>
            <div>
              <label class="label"><TermText path="sandboxes.settings.regions.maxMemory" /></label>
              <input v-model.number="forms[r.region.id].max_memory_mb" class="input font-mono" type="number" min="1" name="max_memory_mb" :disabled="!r.can_manage" />
            </div>
            <div>
              <label class="label"><TermText path="sandboxes.settings.regions.maxDisk" /></label>
              <input v-model.number="forms[r.region.id].max_disk_mb" class="input font-mono" type="number" min="1" name="max_disk_mb" :disabled="!r.can_manage" />
            </div>
          </div>
        </div>

        <div v-if="r.can_manage" class="flex justify-end mt-4">
          <button class="btn-primary text-xs" :disabled="saving[r.region.id]" data-action="save-region" @click="save(r)">{{ t('sandboxes.actions.save') }}</button>
        </div>
      </div>
    </div>
  </section>
</template>

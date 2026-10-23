<script setup>
// Tenant admin → Residency (docs/supply-chain.md): pin an organization to regions and data
// locations, its default region, and its own (stricter) policy gate.
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useResidency } from '../composables/useResidency'
import { supplyChainApi } from '../api'

const props = defineProps({ tenantId: { type: [String, Number], required: true } })

const { t } = useI18n()
const toast = useToast()

const data    = ref(null)
const loading = ref(false)
const saving  = ref(false)
const errors  = ref({})
const form    = reactive({
  residency_enforced: true,
  allowed_region_ids: [],
  allowed_locations:  [],
  default_region_id:  null,
  gate_mode:          null,
  gate_max_critical:  null,
  gate_max_high:      null,
  gate_require_scan:  null,
})

const regions   = computed(() => data.value?.regions ?? [])
const locations = computed(() => data.value?.locations ?? [])
const abilities = computed(() => data.value?.abilities ?? { residency: false, gate: false })
const restricted = computed(() => form.residency_enforced && (form.allowed_region_ids.length > 0 || form.allowed_locations.length > 0))

/** A region the current selection allows (by id or by its data location). */
function allowed(region) {
  if (!restricted.value) return true
  return form.allowed_region_ids.includes(region.id)
    || (!!region.data_location && form.allowed_locations.map(l => l.toUpperCase()).includes(region.data_location.toUpperCase()))
}

const defaultOptions = computed(() => regions.value.filter(allowed))

function fill(d) {
  form.residency_enforced = d.residency_enforced ?? true
  form.allowed_region_ids = [...(d.allowed_region_ids ?? [])]
  form.allowed_locations  = [...(d.allowed_locations ?? [])]
  form.default_region_id  = d.default_region_id ?? null
  form.gate_mode          = d.gate_mode ?? null
  form.gate_max_critical  = d.gate_max_critical ?? null
  form.gate_max_high      = d.gate_max_high ?? null
  form.gate_require_scan  = d.gate_require_scan ?? null
}

async function load() {
  loading.value = true
  try {
    const { data: res } = await supplyChainApi.tenantPolicy(props.tenantId)
    data.value = res?.data ?? null
    if (data.value) fill(data.value)
  } finally {
    loading.value = false
  }
}

function payload() {
  const out = {}
  if (abilities.value.residency) {
    Object.assign(out, {
      residency_enforced: form.residency_enforced,
      allowed_region_ids: form.allowed_region_ids,
      allowed_locations:  form.allowed_locations,
      default_region_id:  form.default_region_id && defaultOptions.value.some(r => r.id === form.default_region_id) ? form.default_region_id : null,
    })
  }
  if (abilities.value.gate) {
    const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v))
    Object.assign(out, {
      gate_mode:         form.gate_mode || null,
      gate_max_critical: num(form.gate_max_critical),
      gate_max_high:     num(form.gate_max_high),
      gate_require_scan: form.gate_mode ? !!form.gate_require_scan : null,
    })
  }
  return out
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const { data: res } = await supplyChainApi.updateTenantPolicy(props.tenantId, payload())
    data.value = res?.data ?? data.value
    fill(data.value)
    // The region pickers read the policy again (it may be the signed-in organization's own)
    useResidency().reset()
    toast.success(t('supplyChain.residency.saved'))
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    toast.error(e?.response?.data?.message ?? t('supplyChain.saveFailed'))
  } finally {
    saving.value = false
  }
}

function toggle(list, value) {
  const i = form[list].indexOf(value)
  i === -1 ? form[list].push(value) : form[list].splice(i, 1)
}

onMounted(load)
</script>

<template>
  <div class="space-y-4" data-tenant-residency>
    <div v-if="loading && !data" class="flex justify-center py-4">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <template v-else-if="data">
      <section class="space-y-2">
        <h4 class="text-xs font-semibold text-gray-200">{{ t('supplyChain.residency.title') }}</h4>
        <p class="text-[11px] text-gray-400">{{ t('supplyChain.residency.intro') }}</p>

        <fieldset :disabled="!abilities.residency" class="space-y-3">
          <label class="flex items-center gap-2 text-xs text-gray-300">
            <input v-model="form.residency_enforced" type="checkbox" data-field="residency_enforced" /> {{ t('supplyChain.residency.enforced') }}
          </label>

          <div>
            <p class="label">{{ t('supplyChain.residency.regions') }}</p>
            <div class="flex flex-wrap gap-2">
              <label v-for="r in regions" :key="r.id" class="flex items-center gap-1.5 text-xs px-2 py-1 rounded-sm border border-gray-800 bg-gray-900/60" :data-region-option="r.name">
                <input type="checkbox" :checked="form.allowed_region_ids.includes(r.id)" @change="toggle('allowed_region_ids', r.id)" />
                <span class="font-mono text-gray-200">{{ r.name }}</span>
                <span v-if="r.data_location" class="text-[10px] text-gray-500">{{ r.data_location }}</span>
              </label>
            </div>
          </div>

          <div>
            <p class="label">{{ t('supplyChain.residency.locations') }}</p>
            <p v-if="!locations.length" class="text-[11px] text-gray-500">{{ t('supplyChain.residency.noLocations') }}</p>
            <div v-else class="flex flex-wrap gap-2">
              <label v-for="l in locations" :key="l" class="flex items-center gap-1.5 text-xs px-2 py-1 rounded-sm border border-gray-800 bg-gray-900/60" :data-location-option="l">
                <input type="checkbox" :checked="form.allowed_locations.includes(l)" @change="toggle('allowed_locations', l)" />
                <span class="font-mono text-gray-200">{{ l }}</span>
              </label>
            </div>
          </div>

          <p v-if="!restricted" class="text-[11px] text-gray-500" data-unrestricted>{{ t('supplyChain.residency.empty') }}</p>

          <div>
            <label class="label">{{ t('supplyChain.residency.defaultRegion') }}</label>
            <select v-model="form.default_region_id" class="input text-xs w-auto" data-field="default_region_id">
              <option :value="null">{{ t('supplyChain.residency.noDefault') }}</option>
              <option v-for="r in defaultOptions" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
            <p v-if="errors.default_region_id" class="text-xs text-red-400 mt-1">{{ errors.default_region_id[0] }}</p>
          </div>
        </fieldset>
      </section>

      <section class="space-y-2 pt-2 border-t border-gray-800/60">
        <h4 class="text-xs font-semibold text-gray-200">{{ t('supplyChain.residency.gate') }}</h4>
        <p class="text-[11px] text-gray-400">{{ t('supplyChain.residency.gateIntro') }}</p>
        <fieldset :disabled="!abilities.gate" class="space-y-2">
          <select v-model="form.gate_mode" class="input text-xs w-auto" data-field="gate_mode">
            <option :value="null">{{ t('supplyChain.residency.inherit') }}</option>
            <option v-for="mode in ['off', 'warn', 'enforce']" :key="mode" :value="mode">{{ t(`supplyChain.region.gateMode.${mode}`) }}</option>
          </select>
          <div v-if="form.gate_mode && form.gate_mode !== 'off'" class="flex flex-wrap items-end gap-3">
            <div>
              <label class="label">{{ t('supplyChain.region.maxCritical') }}</label>
              <input v-model="form.gate_max_critical" type="number" min="0" class="input text-xs w-24" :placeholder="t('supplyChain.region.limitHint')" data-field="gate_max_critical" />
            </div>
            <div>
              <label class="label">{{ t('supplyChain.region.maxHigh') }}</label>
              <input v-model="form.gate_max_high" type="number" min="0" class="input text-xs w-24" :placeholder="t('supplyChain.region.limitHint')" data-field="gate_max_high" />
            </div>
            <label class="flex items-center gap-2 text-xs text-gray-300"><input v-model="form.gate_require_scan" type="checkbox" /> {{ t('supplyChain.region.requireScan') }}</label>
          </div>
        </fieldset>
      </section>

      <div class="flex justify-end">
        <button type="button" class="btn btn-primary text-xs" :disabled="saving || (!abilities.residency && !abilities.gate)" data-save @click="save">{{ t('supplyChain.save') }}</button>
      </div>
    </template>
  </div>
</template>

<script setup>
// Binding limits of a tenant (platform admin, docs/bindings.md "Quotas"): an empty field keeps
// the install default (bindings.quotas), 0 means unlimited; "used" is what the tenant's
// bindings claim today (declared sizes).
import { ref, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { bindingsApi } from '@/api/bindings'
import { useToast } from '@/composables/useToast'
import { Database } from 'lucide-vue-next'

const props = defineProps({
  tenantId: { type: [String, Number], required: true },
})

const KEYS = ['max_bindings', 'kv_mb', 'object_gb', 'postgres_gb', 'dedicated_postgres']

const { t } = useI18n()
const toast = useToast()

const info = ref(null)
const form = reactive(Object.fromEntries(KEYS.map(k => [k, ''])))
const saving = ref(false)
const error = ref(null)

async function load() {
  try {
    const { data } = await bindingsApi.tenantQuota(props.tenantId)
    info.value = data?.data ?? null
    for (const k of KEYS) {
      const v = info.value?.overrides?.[k]
      form[k] = v === null || v === undefined ? '' : String(v)
    }
  } catch {
    info.value = null
  }
}

async function save() {
  saving.value = true
  error.value = null
  try {
    const body = Object.fromEntries(KEYS.map(k => [k, form[k] === '' ? null : Number(form[k])]))
    const { data } = await bindingsApi.updateTenantQuota(props.tenantId, body)
    info.value = data?.data ?? info.value
    toast.success(t('bindings.tenantQuota.saved'))
  } catch (e) {
    error.value = e?.response?.data?.message ?? t('bindings.tenantQuota.failed')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <div v-if="info" class="mt-6 rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-4 space-y-3" data-tenant-binding-quota>
    <div class="flex items-center gap-2">
      <Database class="w-4 h-4 text-sky-400" />
      <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('bindings.tenantQuota.title') }}</p>
    </div>
    <p class="text-[11px] text-gray-400">{{ t('bindings.tenantQuota.intro') }}</p>
    <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
      <div v-for="k in KEYS" :key="k">
        <label class="label">{{ t(`bindings.data.quota.${k}`) }}</label>
        <input v-model="form[k]" type="number" min="0" class="input font-mono text-xs" :placeholder="String(info.defaults?.[k] ?? '')" :data-field="k" />
        <p class="text-[10px] text-gray-500 mt-1">
          {{ t('bindings.tenantQuota.default', { value: info.defaults?.[k] ?? 0 }) }} ·
          {{ t('bindings.tenantQuota.used', { value: info.used?.[k] ?? 0 }) }}
        </p>
      </div>
    </div>
    <p v-if="error" class="text-xs text-red-400">{{ error }}</p>
    <div class="flex justify-end">
      <button type="button" class="btn-primary text-xs" :disabled="saving" @click="save">{{ t('bindings.tenantQuota.save') }}</button>
    </div>
  </div>
</template>

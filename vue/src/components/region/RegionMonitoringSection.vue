<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import MonitoringReuseSection from './MonitoringReuseSection.vue'

// PUT /knative-regions/{id}/monitoring: an existing in-cluster Prometheus / Loki (URL), or RunR's
// own again (empty: installed right away when Serving is ready)
const { t } = useI18n()

const props = defineProps({
  region: { type: Object, required: true },
})
const emit = defineEmits(['saved'])

const value = ref({ prometheusUrl: '', lokiUrl: '', lokiOrgId: '' })
const saving = ref(false)
const saved = ref(false)
const error = ref(null)

const monitoring = computed(() => props.region?.monitoring || {})

function fromRegion() {
  const m = monitoring.value
  value.value = {
    prometheusUrl: m.prometheus_managed === false ? (m.prometheus_url || '') : '',
    lokiUrl: m.loki_managed === false ? (m.loki_url || '') : '',
    lokiOrgId: m.loki_org_id || '',
  }
}
watch(() => props.region, fromRegion, { immediate: true })

async function save() {
  saving.value = true
  saved.value = false
  error.value = null
  try {
    const v = value.value
    const { data } = await knativeRegionsApi.updateMonitoring(props.region.id, {
      prometheus_url: v.prometheusUrl.trim() || null,
      loki_url: v.lokiUrl.trim() || null,
      loki_org_id: v.lokiUrl.trim() ? (v.lokiOrgId.trim() || null) : null,
    })
    saved.value = true
    const region = data.region?.data ?? data.region
    if (region) emit('saved', region)
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally { saving.value = false }
}
</script>

<template>
  <div class="space-y-2" data-testid="region-monitoring">
    <p class="text-[11px] text-gray-400">
      {{ t('settings.regionDetail.monitoring.current', {
        prometheus: monitoring.prometheus_managed === false ? monitoring.prometheus_url : t('settings.regionDetail.monitoring.runrs'),
        loki: monitoring.loki_managed === false ? monitoring.loki_url : t('settings.regionDetail.monitoring.runrs'),
      }) }}
    </p>
    <MonitoringReuseSection v-model="value" />
    <div class="flex items-center gap-3">
      <button type="button" class="btn-secondary text-xs" :disabled="saving" data-testid="save-region-monitoring" @click="save">
        {{ saving ? t('common.saving') : t('settings.regionDetail.monitoring.save') }}
      </button>
      <span v-if="saved" class="text-xs text-emerald-400">{{ t('settings.regionDetail.monitoring.saved') }}</span>
      <span v-else-if="error" class="text-xs text-red-400">{{ error }}</span>
    </div>
  </div>
</template>

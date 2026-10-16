<script setup>
// Region → Workers → Capacity: per resource the always-on budget (the region capacity, no
// overcommit) and the FaaS overcommit budget (capacity × factor − always-on), drawn by
// CapacityBudgets as on the projects page, and the overcommit factors
// (GET / PUT /knative-regions/{id}/capacity; docs/architecture.md → Region capacity and admission).
import { ref, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import TermText from '@/components/TermText.vue'
import CapacityBudgets from './CapacityBudgets.vue'

const props = defineProps({
  regionId: { type: String, default: null },
})
const emit = defineEmits(['saved'])

const { t } = useI18n()
const perms = usePermissionsStore()
const toast = useToast()

const capacity = ref(null)
const loading = ref(false)
const saving = ref(false)
const error = ref(null)
const form = reactive({ faas_cpu_overcommit: 3, faas_memory_overcommit: 1.5 })

const canManage = computed(() => perms.can('regions.manage'))

// the factor inputs, one per resource
const factorFields = ['faas_cpu_overcommit', 'faas_memory_overcommit']

function apply(data) {
  capacity.value = data
  form.faas_cpu_overcommit = data?.faas_cpu_overcommit ?? 3
  form.faas_memory_overcommit = data?.faas_memory_overcommit ?? 1.5
}

function message(e) {
  return e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

async function load() {
  if (!props.regionId) return
  loading.value = true
  error.value = null
  try {
    const { data } = await knativeRegionsApi.capacity(props.regionId)
    apply(data.data)
  } catch (e) {
    error.value = message(e)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = null
  try {
    const { data } = await knativeRegionsApi.updateCapacity(props.regionId, {
      faas_cpu_overcommit: Number(form.faas_cpu_overcommit),
      faas_memory_overcommit: Number(form.faas_memory_overcommit),
    })
    apply(data.data)
    toast.success(t('settings.regionDetail.capacity.saved'))
    emit('saved', data.data)
  } catch (e) {
    error.value = message(e)
  } finally {
    saving.value = false
  }
}

watch(() => props.regionId, load, { immediate: true })
</script>

<template>
  <div class="space-y-3" data-testid="region-capacity">
    <div>
      <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.regionDetail.capacity.title') }}</h3>
      <p class="text-xs text-gray-400 mt-0.5">{{ t('settings.regionDetail.capacity.subtitle') }}</p>
    </div>

    <p v-if="loading && !capacity" class="text-xs text-gray-400">{{ t('common.loading') }}</p>

    <CapacityBudgets v-if="capacity" :capacity="capacity" boxed />

    <p v-if="capacity" class="text-[10px] text-gray-400">{{ t('settings.regionDetail.capacity.hint') }}</p>

    <div v-if="capacity && canManage" class="grid grid-cols-2 gap-3">
      <div v-for="field in factorFields" :key="field">
        <label class="label" :for="`capacity-${field}`"><TermText :path="`settings.regionDetail.capacity.${field}`" /></label>
        <input :id="`capacity-${field}`" v-model="form[field]" type="number" min="1" max="10" step="0.05" class="input font-mono" />
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-400">{{ error }}</p>

    <div v-if="capacity && canManage" class="flex justify-end">
      <button type="button" class="btn-secondary text-xs" :disabled="saving" @click="save">
        {{ saving ? t('common.saving') : t('settings.regionDetail.capacity.save') }}
      </button>
    </div>
  </div>
</template>

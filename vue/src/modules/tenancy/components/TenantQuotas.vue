<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import { useSettingsStore } from '@/stores/settings'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useLatest } from '@/composables/useLatest'
import { Globe, Plus, Pencil, Trash2, Info } from 'lucide-vue-next'
import CapacityBudgets from '@/components/region/CapacityBudgets.vue'
import TenantBindingQuota from './TenantBindingQuota.vue'

const props = defineProps({
  tenantId: { type: [String, Number], required: true },
})

const { t }       = useI18n()
const { confirm }  = useConfirm()
const toast        = useToast()
const settings     = useSettingsStore()

const regions       = ref([])
const loading       = ref(false)
const showForm      = ref(false)
const editingId     = ref(null)

const form      = ref({ region_id: null, cpu_quota_m: 1000, memory_quota_mb: 1024, gpu_quota: null })
const formError = ref(null)
const formSaving = ref(false)

const availableRegions = computed(() => {
  const assignedIds = regions.value.map(r => r.region_id ?? r.id)
  const source = settings.data.assignable_regions ?? settings.data.regions ?? []
  return source.filter(r => !assignedIds.includes(r.id))
})

const selectedRegion = computed(() => {
  if (!form.value.region_id) return null
  const source = settings.data.assignable_regions ?? settings.data.regions ?? []
  return source.find(r => r.id === form.value.region_id) ?? null
})

const isInfiniteSelected = computed(() => selectedRegion.value?.region_mode === 'infinite')

const remainingResources = computed(() => {
  const r = selectedRegion.value
  if (!r || r.region_mode === 'infinite') return null

  let allocCpu = r.allocated_cpu_m ?? 0
  let allocMem = r.allocated_memory_mb ?? 0

  if (editingId.value) {
    const current = regions.value.find(rg => rg.id === editingId.value)
    if (current) {
      allocCpu -= (current.cpu_quota_m ?? 0)
      allocMem -= (current.memory_quota_mb ?? 0)
    }
  }

  return {
    cpu_m:     Math.max(0, (r.total_cpu_m ?? 0) - allocCpu),
    memory_mb: Math.max(0, (r.total_memory_mb ?? 0) - allocMem),
  }
})

const quotaOverflow = computed(() => {
  if (!remainingResources.value) return false
  return form.value.cpu_quota_m > remainingResources.value.cpu_m
      || form.value.memory_quota_mb > remainingResources.value.memory_mb
})

watch(isInfiniteSelected, (val) => {
  if (val) {
    form.value.cpu_quota_m = 0
    form.value.memory_quota_mb = 0
  }
})

onMounted(loadRegions)

// The newest list wins; a failed reload after a saved change is not that change's error
const latestRegions = useLatest()
async function loadRegions() {
  const current = latestRegions.begin()
  loading.value = true
  try {
    const { data } = await tenantsApi.listRegions(props.tenantId)
    if (current()) regions.value = Array.isArray(data) ? data : (data.data ?? [])
  } catch {
    /* the list keeps what it showed */
  } finally {
    if (current()) loading.value = false
  }
}

function openAssign() {
  editingId.value = null
  form.value = { region_id: null, cpu_quota_m: 1000, memory_quota_mb: 1024, gpu_quota: null }
  formError.value = null
  showForm.value = true
}

function openEditQuota(region) {
  if (region.region_mode === 'infinite') return
  editingId.value = region.id
  form.value = {
    region_id:       region.region_id ?? region.id,
    cpu_quota_m:     region.cpu_quota_m ?? region.pivot?.cpu_quota_m ?? 1000,
    memory_quota_mb: region.memory_quota_mb ?? region.pivot?.memory_quota_mb ?? 1024,
    gpu_quota:       region.gpu_quota ?? region.pivot?.gpu_quota ?? null,
  }
  formError.value = null
  showForm.value = true
}

function cancelForm() {
  showForm.value = false
  editingId.value = null
  formError.value = null
}

async function submitForm() {
  formSaving.value = true
  formError.value  = null
  try {
    // GPU quota: empty = no limit (null), 0 = no GPUs
    const gpuQuota = form.value.gpu_quota === '' || form.value.gpu_quota === null || form.value.gpu_quota === undefined
      ? null : Math.max(0, parseInt(form.value.gpu_quota, 10) || 0)
    if (editingId.value) {
      await tenantsApi.updateRegionQuota(props.tenantId, editingId.value, {
        cpu_quota_m:     form.value.cpu_quota_m,
        memory_quota_mb: form.value.memory_quota_mb,
      })
      await tenantsApi.updateRegionGpuQuota(props.tenantId, editingId.value, { gpu_quota: gpuQuota })
      toast.success(t('tenancy.quotaUpdated'))
    } else {
      const { gpu_quota, ...assign } = form.value
      await tenantsApi.assignRegion(props.tenantId, assign)
      // Assigned: if the GPU quota below fails, saving again edits this assignment instead of
      // assigning the region a second time ("already assigned")
      editingId.value = form.value.region_id
      loadRegions()
      if (gpuQuota !== null) await tenantsApi.updateRegionGpuQuota(props.tenantId, form.value.region_id, { gpu_quota: gpuQuota })
      toast.success(t('tenancy.regionAssigned'))
    }
    showForm.value = false
    editingId.value = null
    await loadRegions()
  } catch (e) {
    formError.value = e.response?.data?.message
      ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
      ?? t('common.error')
  } finally {
    formSaving.value = false
  }
}

async function removeRegion(region) {
  const ok = await confirm({
    title:       t('tenancy.removeRegionTitle'),
    message:     t('tenancy.removeRegionConfirm', { name: region.name }),
    destructive: true,
  })
  if (!ok) return
  try {
    await tenantsApi.removeRegion(props.tenantId, region.id)
    toast.success(t('tenancy.regionRemoved'))
    await loadRegions()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

function fmtCpu(m)  { return m >= 1000 ? (m / 1000).toFixed(1) + ' core' : m + 'm' }
function fmtMem(mb) { return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : mb + ' MB' }
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('tenancy.regions') }}</p>
      <button class="btn-primary text-xs flex items-center gap-1.5" @click="openAssign">
        <Plus class="w-3.5 h-3.5" />{{ t('tenancy.assignRegion') }}
      </button>
    </div>

    <!-- Assign / edit form -->
    <div v-if="showForm" class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-4 mb-4 space-y-3">
      <p class="text-sm font-semibold text-gray-100">{{ t('tenancy.assignRegion') }}</p>
      <div class="grid grid-cols-4 gap-3">
        <div>
          <label class="label">{{ t('tenancy.region') }}</label>
          <select v-model="form.region_id" class="input" :disabled="!!editingId">
            <option :value="null" disabled>{{ t('tenancy.selectRegion') }}</option>
            <option v-for="r in availableRegions" :key="r.id" :value="r.id">{{ r.name }}</option>
            <option v-if="editingId" :value="form.region_id" selected>
              {{ regions.find(r => r.id === editingId)?.name }}
            </option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('tenancy.cpuQuota') }}</label>
          <input v-model.number="form.cpu_quota_m" type="number" class="input font-mono" min="0"
            :max="remainingResources?.cpu_m"
            :disabled="isInfiniteSelected" />
        </div>
        <div>
          <label class="label">{{ t('tenancy.memoryQuota') }}</label>
          <input v-model.number="form.memory_quota_mb" type="number" class="input font-mono" min="0"
            :max="remainingResources?.memory_mb"
            :disabled="isInfiniteSelected" />
        </div>
        <div>
          <label class="label">{{ t('tenancy.gpuQuota') }}</label>
          <input v-model.number="form.gpu_quota" type="number" class="input font-mono" min="0"
            :placeholder="t('tenancy.gpuQuotaUnlimited')" data-testid="tenant-gpu-quota" />
        </div>
      </div>
      <p class="text-[11px] text-gray-400">{{ t('tenancy.gpuQuotaHint') }}</p>

      <!-- Info banners -->
      <div v-if="isInfiniteSelected" class="flex items-center gap-2 rounded-lg border border-blue-800/40 bg-blue-900/20 px-3 py-2 text-xs text-blue-400">
        <Info class="w-3.5 h-3.5 shrink-0" />{{ t('tenancy.infiniteQuotaHint') }}
      </div>
      <div v-if="remainingResources" class="flex items-center gap-2 rounded-lg border border-gray-700 bg-gray-800/40 px-3 py-2 text-[11px] text-gray-400">
        <Info class="w-3 h-3 shrink-0" />
        {{ t('tenancy.remainingCapacity') }}:
        <span class="font-mono text-gray-100">{{ fmtCpu(remainingResources.cpu_m) }}</span> CPU
        <span class="text-gray-400 mx-0.5">/</span>
        <span class="font-mono text-gray-100">{{ fmtMem(remainingResources.memory_mb) }}</span> {{ t('tenancy.usageMemory') }}
      </div>
      <p v-if="quotaOverflow" class="text-xs text-red-400">{{ t('tenancy.quotaExceedsCapacity') }}</p>
      <p v-if="formError" class="text-xs text-red-400">{{ formError }}</p>
      <div class="flex gap-2">
        <button class="btn-primary text-xs" :disabled="formSaving || quotaOverflow" @click="submitForm">
          {{ formSaving ? t('common.saving') : t('common.save') }}
        </button>
        <button class="btn-secondary text-xs" @click="cancelForm">{{ t('common.cancel') }}</button>
      </div>
    </div>

    <!-- Loading -->
    <p v-if="loading" class="text-xs text-gray-400 py-3 text-center">{{ t('common.loading') }}</p>

    <!-- Regions list -->
    <div v-else class="space-y-2">
      <div v-for="region in regions" :key="region.id"
        class="rounded-lg border border-gray-800 bg-gray-900/40 px-4 py-3 text-xs">
        <div class="flex items-center gap-3 mb-2">
          <div class="w-7 h-7 rounded-lg flex items-center justify-center shrink-0 bg-gray-800/60 border border-gray-700/60">
            <Globe class="w-3.5 h-3.5 text-gray-400" />
          </div>
          <span class="font-semibold text-gray-100 font-mono">{{ region.name }}</span>
          <span v-if="region.region_mode === 'infinite'"
            class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border bg-blue-900/40 border-blue-800/40 text-blue-400">
            {{ t('tenancy.regionMode_infinite') }}
          </span>
          <span v-else class="text-gray-400 font-mono text-[11px]">{{ fmtCpu(region.cpu_quota_m ?? 0) }} / {{ fmtMem(region.memory_quota_mb ?? 0) }}</span>
          <span v-if="region.gpu_quota !== null && region.gpu_quota !== undefined" class="text-amber-400/80 font-mono text-[11px]" data-testid="tenant-gpu-quota-value">
            {{ t('tenancy.gpuQuotaValue', { n: region.gpu_quota }) }}
          </span>
          <div class="flex-1" />
          <button v-if="region.region_mode !== 'infinite'"
            class="p-1 rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800 transition-colors"
            @click="openEditQuota(region)">
            <Pencil class="w-3.5 h-3.5" />
          </button>
          <button class="p-1 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20 transition-colors"
            @click="removeRegion(region)">
            <Trash2 class="w-3.5 h-3.5" />
          </button>
        </div>
        <!-- The quota's always-on and FaaS overcommit budgets, as on the organization's projects page -->
        <div v-if="region.region_mode !== 'infinite' && region.capacity" class="ml-10" :data-testid="`quota-budgets-${region.id}`">
          <CapacityBudgets :capacity="region.capacity" icons />
        </div>
      </div>

      <!-- Empty state -->
      <div v-if="regions.length === 0" class="py-8 text-center">
        <div class="w-10 h-10 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-2">
          <Globe class="w-5 h-5 text-gray-400" />
        </div>
        <p class="text-xs text-gray-400">{{ t('tenancy.noRegionsAssigned') }}</p>
      </div>
    </div>

    <!-- Binding limits (docs/bindings.md) -->
    <TenantBindingQuota :tenant-id="tenantId" />
  </div>
</template>

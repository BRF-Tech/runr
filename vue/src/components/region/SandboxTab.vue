<script setup>
import { watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { useSandboxTab } from '@/composables/useSandboxTab'
import SandboxRuntimeCard from './SandboxRuntimeCard.vue'
import KataShimSelector from './KataShimSelector.vue'
import WasmSupportCard from './WasmSupportCard.vue'

const { t } = useI18n()
const { confirm } = useConfirm()

const props = defineProps({
  regionId: { type: String, default: null },
  region:   { type: Object, default: null },
})
const emit = defineEmits(['saved'])

const regionIdRef = computed(() => props.regionId)

const {
  kataStatus, kataMessage, kataHypervisor,
  gvisorStatus, gvisorMessage,
  mapping, mappingSaving, mappingError, mappingSuccess,
  installing, runtimeOptions,
  shims, shimsLoading, selectedShims, kvm, kataBlocked,
  fcSelected, devmapperLimits, devmapperPoolGb,
  initFromRegion, installRuntime, confirmUninstall,
  saveMapping, sandboxStatusLabel, loadShims,
} = useSandboxTab(regionIdRef, emit, t, confirm)

const kvmNodesWithout = computed(() =>
  Object.entries(kvm.value?.nodes || {}).filter(([, v]) => v !== true).map(([n]) => n).join(', ')
)

watch(() => props.region, (r) => initFromRegion(r), { immediate: true })
watch(() => props.regionId, (id) => { if (id) loadShims() }, { immediate: true })

const hasSandbox = computed(() =>
  kataStatus.value === 'ready' || gvisorStatus.value === 'ready'
)

const mappingFields = ['xaas', 'faas', 'caas', 'build']
</script>

<template>
  <div class="space-y-6">
    <!-- Title -->
    <div>
      <h3 class="text-sm font-semibold text-gray-100">{{ t('settings.regionDetail.sandbox.title') }}</h3>
      <p class="text-xs text-gray-400 mt-1">{{ t('settings.regionDetail.sandbox.subtitle') }}</p>
    </div>

    <!-- Kata card with shim selector -->
    <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-4 space-y-3">
      <SandboxRuntimeCard
        runtime="kata"
        :status="kataStatus"
        :message="kataMessage"
        :installing="installing.kata"
        :status-label="sandboxStatusLabel(kataStatus)"
        :hypervisor="kataHypervisor"
        :unavailable="kataBlocked"
        @install="installRuntime('kata')"
        @uninstall="confirmUninstall('kata')"
      />
      <!-- Hardware virtualization (/dev/kvm) on the nodes: Kata starts VMs -->
      <p v-if="kataBlocked && kataStatus === 'ready'" class="text-xs text-red-300/90 bg-red-900/20 rounded-sm px-3 py-2">
        {{ t('settings.regionDetail.sandbox.kata.kvmMissingInstalled') }}
      </p>
      <p v-else-if="kataBlocked" class="text-xs text-amber-300/90 bg-amber-900/20 rounded-sm px-3 py-2">
        {{ t('settings.regionDetail.sandbox.kata.kvmMissing') }}
      </p>
      <p v-else-if="kvm && kvm.state === 'mixed'" class="text-[11px] text-gray-400">
        {{ t('settings.regionDetail.sandbox.kata.kvmMixed', { nodes: kvm.kvm_nodes.join(', '), without: kvmNodesWithout }) }}
      </p>
      <p v-else-if="kvm && kvm.probing && kvm.state === 'unknown'" class="text-[11px] text-gray-400">
        {{ t('settings.regionDetail.sandbox.kata.kvmChecking') }}
      </p>
      <!-- Shim selector: only when not installed or error (and the nodes can run VMs) -->
      <template v-if="(kataStatus === 'not_installed' || kataStatus === 'error') && !kataBlocked">
        <KataShimSelector
          v-model="selectedShims"
          :shims="shims"
          :loading="shimsLoading"
        />
        <!-- Firecracker: RunR sets up containerd's devmapper snapshotter (thin-pool size) -->
        <div v-if="fcSelected" class="space-y-1">
          <label class="text-xs text-gray-400 font-medium" for="kata-devmapper-pool">
            {{ t('settings.regionDetail.sandbox.kata.devmapperPool') }}
          </label>
          <div class="flex items-center gap-2">
            <input
              id="kata-devmapper-pool"
              v-model.number="devmapperPoolGb"
              type="number"
              :min="devmapperLimits.min_pool_gb"
              :max="devmapperLimits.max_pool_gb"
              class="w-24 rounded-md border border-gray-800 bg-gray-900/40 px-2 py-1 text-xs text-gray-100"
            />
            <span class="text-xs text-gray-400">GiB</span>
          </div>
          <p class="text-[11px] text-gray-400">{{ t('settings.regionDetail.sandbox.kata.fcHint') }}</p>
        </div>
        <p class="text-[11px] text-amber-400/90">
          {{ t('settings.regionDetail.sandbox.kata.installWarning') }}
        </p>
      </template>
    </div>

    <!-- gVisor card -->
    <div class="bg-gray-900/60 border border-gray-800 rounded-xl p-4">
      <SandboxRuntimeCard
        runtime="gvisor"
        :status="gvisorStatus"
        :message="gvisorMessage"
        :installing="installing.gvisor"
        :status-label="sandboxStatusLabel(gvisorStatus)"
        @install="installRuntime('gvisor')"
        @uninstall="confirmUninstall('gvisor')"
      />
    </div>

    <!-- WebAssembly support (runwasi shims) — its own card, not a project-type mapping -->
    <WasmSupportCard :region-id="regionId" :region="region" @saved="emit('saved', $event)" />

    <!-- Mapping section with buttons instead of dropdowns -->
    <div class="bg-gray-900/40 border border-gray-800 rounded-xl p-4 space-y-4">
      <div>
        <h4 class="text-sm font-medium text-gray-100">{{ t('settings.regionDetail.sandbox.mapping.title') }}</h4>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('settings.regionDetail.sandbox.mapping.subtitle') }}</p>
      </div>

      <p v-if="!hasSandbox" class="text-xs text-gray-400 italic">
        {{ t('settings.regionDetail.sandbox.mapping.notInstalledHint') }}
      </p>

      <div v-else class="space-y-3">
        <div v-for="field in mappingFields" :key="field" class="space-y-1">
          <label class="text-xs text-gray-400 font-medium">
            {{ t(`settings.regionDetail.sandbox.mapping.${field}`) }}
          </label>
          <div class="flex flex-wrap gap-1.5">
            <button
              v-for="opt in runtimeOptions" :key="opt.value"
              type="button"
              class="rounded-md border px-3 py-1.5 text-xs font-medium transition-colors"
              :class="mapping[field] === opt.value
                ? 'border-brand-500/60 bg-brand-900/20 text-brand-400'
                : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100 hover:border-gray-700'"
              @click="mapping[field] = opt.value"
            >
              {{ opt.label }}
            </button>
          </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
          <button class="btn-primary text-xs" :disabled="mappingSaving" @click="saveMapping">
            {{ mappingSaving ? '...' : t('settings.regionDetail.sandbox.mapping.save') }}
          </button>
          <span v-if="mappingSuccess" class="text-xs text-emerald-400">
            {{ t('settings.regionDetail.sandbox.mapping.saved') }}
          </span>
          <span v-if="mappingError" class="text-xs text-red-400">{{ mappingError }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

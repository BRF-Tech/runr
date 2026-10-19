<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import KubeconfigInput from './KubeconfigInput.vue'
import { moduleRegistry } from '@/modules/index'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()
const isCentralAdmin = computed(() => moduleRegistry.isCentralAdminFn?.() ?? false)

defineProps({
  isEdit:          { type: Boolean, default: false },
  isWizard:        { type: Boolean, default: false },
  isStepReadonly:  { type: Boolean, default: false },
  form:            { type: Object, required: true },
  region:          { type: Object, default: null },
  kubeconfigMode:  { type: String, default: 'file' },
  kubeconfigName:  { type: String, default: '' },
  kubeconfigPaste: { type: String, default: '' },
  saving:          { type: Boolean, default: false },
  error:           { type: String, default: null },
  canSaveGeneral:  { type: Boolean, default: false },
  dnsInstruction:  { type: Object, default: null },
  dnsResolved:     { type: [Boolean, null], default: null },
  togglingStatus:  { type: Boolean, default: false },
})

defineEmits([
  'save', 'close', 'next', 'change-status',
  'update:form-name', 'update:form-active', 'update:form-region-mode',
  'update:kubeconfig-mode', 'update:kubeconfig-paste', 'kubeconfig-file-change',
])
</script>

<template>
  <!-- Edit mode -->
  <div v-if="isEdit" class="space-y-4">
    <!-- Region name (readonly) -->
    <div>
      <label class="label">{{ t('settings.regions.name') }}</label>
      <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded-sm px-3 py-2">{{ form.name }}</p>
      <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regions.nameReadonly') }}</p>
    </div>

    <!-- Server URL -->
    <div v-if="region?.server_url">
      <label class="label">{{ t('settings.regions.serverUrl') }}</label>
      <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded-sm px-3 py-2">{{ region.server_url }}</p>
    </div>

    <!-- Region mode (readonly in edit, central admin only) -->
    <div v-if="isCentralAdmin && form.region_mode" class="flex items-center justify-between rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5">
      <span class="text-xs text-gray-400">{{ t('settings.regionDetail.regionMode') }}</span>
      <span class="text-xs font-mono"
        :class="form.region_mode === 'infinite' ? 'text-violet-400' : 'text-sky-400'">
        {{ t('tenancy.regionMode_' + form.region_mode) }}
      </span>
    </div>

    <!-- Region status selector -->
    <div class="rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5 space-y-2">
      <span class="text-xs text-gray-400">{{ t('settings.regions.regionStatus') }}</span>
      <div class="flex gap-1.5">
        <button v-for="s in ['active', 'inactive', 'maintenance']" :key="s"
          type="button"
          class="flex-1 text-[11px] py-1.5 rounded-md transition-colors font-medium"
          :class="form.region_status === s
            ? (s === 'active' ? 'bg-emerald-900/60 text-emerald-400 ring-1 ring-emerald-700'
              : s === 'maintenance' ? 'bg-amber-900/60 text-amber-400 ring-1 ring-amber-700'
              : 'bg-gray-700 text-gray-100 ring-1 ring-gray-600')
            : 'bg-gray-900/40 text-gray-400 hover:text-gray-400'"
          :disabled="togglingStatus || form.region_status === s"
          @click="$emit('change-status', s)">
          {{ t('settings.regions.status_' + s) }}
        </button>
      </div>
    </div>

    <!-- DNS Section (if serving is ready) -->
    <div v-if="region?.has_serving && dnsInstruction" class="rounded-md border bg-gray-900/60 px-3 py-3 space-y-2"
      :class="dnsResolved === false ? 'border-yellow-700/60' : 'border-gray-800'">
      <p class="text-xs text-gray-400 font-medium">{{ t('settings.regionDetail.knative.dnsTitle') }}</p>
      <div class="text-xs font-mono space-y-1">
        <p class="text-gray-400">{{ t('settings.regionDetail.knative.dnsWildcard') }}</p>
        <p class="text-brand-400 select-all">{{ dnsInstruction.wildcard }}</p>
        <template v-if="dnsInstruction.type && dnsInstruction.target">
          <p class="text-gray-400 mt-2">{{ t('settings.regionDetail.knative.dnsRecord', { type: dnsInstruction.type }) }}</p>
          <p class="text-emerald-400 select-all">{{ dnsInstruction.target }}</p>
        </template>
        <p v-else class="text-gray-400 italic mt-2">{{ t('settings.regionDetail.knative.dnsNoIp') }}</p>
      </div>
      <!-- DNS status -->
      <div class="flex items-center gap-2 mt-2 pt-2 border-t border-gray-800/40">
        <template v-if="dnsResolved === true">
          <span class="w-2 h-2 rounded-full bg-emerald-500 shrink-0" />
          <span class="text-[11px] text-emerald-400">{{ t('settings.regions.dnsResolved') }}</span>
        </template>
        <template v-else-if="dnsResolved === false">
          <span class="w-2 h-2 rounded-full bg-yellow-500 shrink-0 animate-pulse" />
          <span class="text-[11px] text-yellow-400">{{ t('settings.regions.dnsNotResolved') }}</span>
        </template>
        <template v-else>
          <span class="w-2 h-2 rounded-full bg-gray-600 shrink-0 animate-pulse" />
          <span class="text-[11px] text-gray-400">{{ t('settings.regions.dnsChecking') }}</span>
        </template>
      </div>
    </div>

    <!-- DNS warning (serving ready but no dns_instruction) -->
    <div v-else-if="region?.has_serving && !dnsInstruction"
      class="rounded-md border border-yellow-700/60 bg-yellow-900/10 px-3 py-2.5">
      <p class="text-xs text-yellow-400">{{ t('settings.regions.dnsNotConfigured') }}</p>
    </div>
  </div>

  <!-- Wizard step 1: readonly -->
  <div v-else-if="isStepReadonly" class="space-y-4">
    <div>
      <label class="label">{{ t('settings.regions.name') }}</label>
      <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded-sm px-3 py-2">{{ form.name }}</p>
    </div>
    <div>
      <label class="label"><TermText path="settings.regions.kubeconfig" /></label>
      <div class="text-xs text-emerald-500 font-mono">{{ t('settings.regions.kubeconfigSet') }}</div>
    </div>
    <div class="flex justify-between gap-3 pt-3 border-t border-gray-800/60">
      <button type="button" class="btn-secondary" @click="$emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" class="btn-primary" @click="$emit('next')">{{ t('common.next') }}</button>
    </div>
  </div>

  <!-- Wizard step 1: editable -->
  <div v-else class="space-y-4">
    <div>
      <label class="label">{{ t('settings.regions.name') }}</label>
      <input :value="form.name" class="input font-mono" placeholder="istanbul-1a"
        @input="$emit('update:form-name', $event.target.value)"
        @keyup.enter="canSaveGeneral && $emit('save')" />
    </div>
    <!-- Region mode selector (central admin only) -->
    <div v-if="isCentralAdmin">
      <label class="label">{{ t('settings.regionDetail.regionMode') }}</label>
      <div class="grid grid-cols-2 gap-2">
        <button type="button" class="rounded-md border px-3 py-2 text-xs text-left transition-colors"
          :class="form.region_mode === 'managed' ? 'border-sky-500/60 bg-sky-900/20 text-sky-400' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
          @click="$emit('update:form-region-mode', 'managed')">
          <span class="font-medium">{{ t('tenancy.regionMode_managed') }}</span>
          <p class="text-[10px] mt-0.5 opacity-70">{{ t('settings.regionDetail.regionModeManaged') }}</p>
        </button>
        <button type="button" class="rounded-md border px-3 py-2 text-xs text-left transition-colors"
          :class="form.region_mode === 'infinite' ? 'border-violet-500/60 bg-violet-900/20 text-violet-400' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
          @click="$emit('update:form-region-mode', 'infinite')">
          <span class="font-medium">{{ t('tenancy.regionMode_infinite') }}</span>
          <p class="text-[10px] mt-0.5 opacity-70">{{ t('settings.regionDetail.regionModeInfinite') }}</p>
        </button>
      </div>
    </div>
    <!-- Fields of modules (moduleRegistry.regionCreateExtensions, e.g. the billing type) -->
    <template v-if="form.extensions">
      <component :is="ext.component" v-for="ext in moduleRegistry.regionCreateExtensions" :key="ext.key" v-model="form.extensions[ext.key]" />
    </template>
    <KubeconfigInput
      :mode="kubeconfigMode" :file-name="kubeconfigName" :paste-value="kubeconfigPaste"
      @update:mode="$emit('update:kubeconfig-mode', $event)" @update:paste-value="$emit('update:kubeconfig-paste', $event)"
      @file-change="$emit('kubeconfig-file-change', $event)" />
    <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
    <div class="flex justify-end gap-3 pt-3 border-t border-gray-800/60">
      <button type="button" class="btn-secondary" @click="$emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" class="btn-primary" :disabled="saving || !canSaveGeneral" @click="$emit('save')">
        {{ saving ? t('common.saving') : t('common.next') }}
      </button>
    </div>
  </div>
</template>

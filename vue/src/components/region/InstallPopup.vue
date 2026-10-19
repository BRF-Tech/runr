<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps({
  open:               { type: Boolean, default: false },
  component:          { type: String, default: null },   // 'operator' | 'serving'
  mode:               { type: String, default: 'install' }, // 'install' | 'upgrade'
  namespace:          { type: String, default: '' },
  nsCheckResult:      { type: String, default: null },
  nsChecking:         { type: Boolean, default: false },
  version:            { type: String, default: null },
  compatibleVersions: { type: Array, default: () => [] },
  versionsLoading:    { type: Boolean, default: false },
  error:              { type: String, default: null },
  submitting:         { type: Boolean, default: false },
})
defineEmits([
  'close',
  'update:namespace', 'update:version',
  'check-ns', 'confirm',
])

const confirmDisabled = () => {
  if (props.submitting || !props.namespace.trim()) return true
  // an upgrade needs the next version (none: the cluster supports no newer one)
  if (props.mode === 'upgrade' && props.component === 'serving' && !props.version) return true
  return false
}
</script>

<template>
  <div v-if="open" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 70">
    <div class="absolute inset-0 bg-black/50" @click="$emit('close')" />
    <div class="relative card w-full max-w-md space-y-4">
      <!-- Title -->
      <h4 class="font-semibold text-sm">
        {{ mode === 'upgrade'
          ? t('settings.regionDetail.installPopup.upgradeTitle', { component: t(`settings.regionDetail.component.${component}`) })
          : t('settings.regionDetail.installPopup.installTitle', { component: t(`settings.regionDetail.component.${component}`) }) }}
      </h4>

      <!-- Namespace -->
      <div>
        <label class="label">{{ t('settings.regionDetail.installPopup.namespace') }}</label>
        <p class="text-[10px] text-gray-400 mb-1">{{ t('settings.regionDetail.installPopup.namespaceHint') }}</p>
        <div class="flex gap-2">
          <input :value="namespace" class="input font-mono text-xs flex-1"
            :disabled="mode === 'upgrade'"
            @input="$emit('update:namespace', $event.target.value)" />
          <button v-if="mode !== 'upgrade'" type="button" class="btn-secondary text-xs shrink-0"
            :disabled="nsChecking || !namespace.trim()"
            @click="$emit('check-ns')">
            {{ nsChecking ? t('settings.regionDetail.installPopup.checking') : t('settings.regionDetail.installPopup.check') }}
          </button>
        </div>
        <p v-if="nsCheckResult === 'available' && mode !== 'upgrade'" class="text-[10px] text-emerald-400 mt-1">{{ t('settings.regionDetail.installPopup.available') }}</p>
        <p v-if="nsCheckResult === 'exists'" class="text-[10px] text-red-400 mt-1">{{ t('settings.regionDetail.installPopup.exists') }}</p>
      </div>

      <!-- Serving: version selector -->
      <div v-if="component === 'serving'">
        <label class="label">{{ t('settings.regionDetail.installPopup.version') }}</label>
        <select :value="version" class="input font-mono text-xs" :disabled="versionsLoading"
          @change="$emit('update:version', $event.target.value)">
          <option v-for="v in compatibleVersions" :key="v" :value="v">{{ v }}</option>
        </select>
        <template v-if="mode === 'upgrade'">
          <p v-if="!versionsLoading && !compatibleVersions.length" class="text-[11px] text-amber-400 mt-1" data-testid="no-upgrade">
            {{ t('settings.regionDetail.installPopup.noUpgrade') }}
          </p>
          <p v-else class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.installPopup.upgradeHint') }}</p>
        </template>
      </div>

      <!-- Error -->
      <p v-if="error" class="text-xs text-red-400 bg-red-900/20 border border-red-800/40 rounded-sm px-3 py-2">{{ error }}</p>

      <!-- Actions -->
      <div class="flex justify-end gap-3 pt-3 border-t border-gray-800/60">
        <button type="button" class="btn-secondary text-xs" @click="$emit('close')">{{ t('common.cancel') }}</button>
        <button type="button" class="btn-primary text-xs"
          :disabled="confirmDisabled()"
          @click="$emit('confirm')">
          {{ submitting ? t('common.loading') : (mode === 'upgrade' ? t('settings.regionDetail.installPopup.upgradeBtn') : t('settings.regionDetail.installPopup.installBtn')) }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { statusBadgeClass } from '@/utils/formatters'
import ComponentLogBox from './ComponentLogBox.vue'

const { t } = useI18n()

const props = defineProps({
  component:      { type: String, required: true }, // 'operator' | 'serving'
  status:         { type: String, default: 'not_installed' },
  message:        { type: String, default: '' },
  namespace:      { type: String, default: '' },
  version:        { type: String, default: null },
  isInstalling:   { type: Boolean, default: false },
  operatorReady:  { type: Boolean, default: false },
  servingReady:   { type: Boolean, default: false },
  logCopied:      { type: Boolean, default: false },
  readonly:       { type: Boolean, default: false },
})
defineEmits(['install', 'upgrade', 'uninstall', 'copy-logs'])

const isReady = () => props.status === 'ready'

function statusLabel(status) {
  return t(`settings.regionDetail.knative.status${capitalize(status)}`)
}

function capitalize(s) {
  if (!s) return ''
  return s.replace(/(^|_)(\w)/g, (_, p, c) => c.toUpperCase())
}

const canInstall = () => {
  if (props.component === 'operator') {
    return props.status === 'not_installed' || props.status === 'error'
  }
  return (props.status === 'not_installed' || props.status === 'error') && props.operatorReady
}

const canUninstall = () => {
  if (props.component === 'operator') {
    return props.status === 'ready' && !props.servingReady
  }
  return props.status === 'ready'
}

const canUpgrade = () => {
  return props.component !== 'operator' && props.status === 'ready'
}
</script>

<template>
  <div class="space-y-5">
    <!-- Title -->
    <div>
      <p class="text-sm font-medium text-gray-100">{{ t(`settings.regionDetail.${component}.title`) }}</p>
      <p class="text-xs text-gray-400">{{ t(`settings.regionDetail.${component}.subtitle`) }}</p>
    </div>

    <!-- Readonly view -->
    <template v-if="readonly">
      <div v-if="namespace">
        <label class="label">{{ t('settings.regionDetail.namespace.label') }}</label>
        <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded px-3 py-2">{{ namespace }}</p>
      </div>
      <div v-if="version && component !== 'operator'">
        <label class="label">{{ t(`settings.regionDetail.${component}.versionLabel`) }}</label>
        <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded px-3 py-2">{{ version }}</p>
      </div>
      <div class="flex items-center gap-3">
        <span :class="statusBadgeClass(status)">{{ statusLabel(status) }}</span>
      </div>
    </template>

    <!-- Interactive view -->
    <template v-else>
      <!-- Status badge -->
      <div class="flex items-center gap-3">
        <span :class="statusBadgeClass(status)">{{ statusLabel(status) }}</span>
      </div>

      <!-- Namespace (readonly when installed) -->
      <div v-if="isReady()">
        <label class="label">{{ t('settings.regionDetail.namespace.label') }}</label>
        <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded px-3 py-2">{{ namespace }}</p>
        <p class="text-[10px] text-gray-400 mt-1">{{ t('settings.regionDetail.namespace.readonly') }}</p>
      </div>

      <!-- Ingress class (serving only) -->
      <div v-if="component === 'serving' && isReady()">
        <label class="label">{{ t('settings.regionDetail.serving.ingressClass') }}</label>
        <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded px-3 py-2">Traefik</p>
      </div>

      <!-- Version (serving, when ready) -->
      <div v-if="component !== 'operator' && isReady() && version">
        <label class="label">{{ t(`settings.regionDetail.${component}.versionLabel`) }}</label>
        <p class="text-xs text-gray-400 font-mono bg-gray-900/60 rounded px-3 py-2">{{ version }}</p>
      </div>

      <!-- Adopted info -->
      <div v-if="isReady() && message && message.includes('Adopted')" class="bg-blue-500/10 border border-blue-500/20 rounded-md px-4 py-2.5">
        <p class="text-xs text-blue-400">{{ t('settings.regionDetail.adopted') }}</p>
      </div>

      <!-- Log box -->
      <ComponentLogBox v-if="!message || !message.includes('Adopted')"
        :status="status"
        :message="message"
        :log-copied="logCopied"
        @copy="$emit('copy-logs')"
      />

      <!-- DNS instruction moved to GeneralTab -->

      <!-- Actions -->
      <div class="flex gap-3">
        <button v-if="canInstall()"
          type="button" class="btn-primary text-xs" :disabled="isInstalling" @click="$emit('install')">
          {{ isInstalling ? t('common.loading') : t(`settings.regionDetail.${component}.installBtn`) }}
        </button>
        <button v-if="canUpgrade()"
          type="button" class="btn-primary text-xs" @click="$emit('upgrade')">
          {{ t(`settings.regionDetail.${component}.upgradeBtn`) }}
        </button>
        <button v-if="canUninstall()"
          type="button" class="btn-secondary text-xs !text-red-400 hover:!text-red-300" @click="$emit('uninstall')">
          {{ t(`settings.regionDetail.${component}.uninstallBtn`) }}
        </button>
      </div>
    </template>
  </div>
</template>

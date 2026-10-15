<script setup>
import { useI18n } from 'vue-i18n'
import { redisStatusBadgeClass } from '@/utils/formatters'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

defineProps({
  redisStatus:          { type: String, default: null },
  redisAddress:         { type: String, default: '' },
  redisMode:            { type: String, default: null },
  redisError:           { type: String, default: null },
  popupRedisMode:       { type: String, default: null },
  popupRedisAddress:    { type: String, default: '' },
  popupRedisPassword:   { type: String, default: '' },
  popupRedisTestResult: { type: String, default: null },
  popupRedisTesting:    { type: Boolean, default: false },
  saving:               { type: Boolean, default: false },
  readonly:             { type: Boolean, default: false },
})

defineEmits([
  'update:popupRedisMode', 'update:popupRedisAddress', 'update:popupRedisPassword',
  'configure', 'remove', 'test',
])

function redisStatusLabel(status) {
  if (!status) return t('settings.regionDetail.redis.statusNone')
  return t(`settings.regionDetail.redis.status${status.charAt(0).toUpperCase() + status.slice(1)}`)
}

const modeCards = [
  { key: 'managed',    labelKey: 'modeManaged',    descKey: 'modeManagedDesc' },
  { key: 'in-cluster', labelKey: 'modeInCluster',  descKey: 'modeInClusterDesc' },
  { key: 'external',   labelKey: 'modeExternal',   descKey: 'modeExternalDesc' },
]
</script>

<template>
  <div class="space-y-4">
    <div>
      <p class="text-sm font-medium text-gray-100">{{ t('settings.regionDetail.redis.title') }}</p>
      <p class="text-xs text-gray-400 mb-3">{{ t('settings.regionDetail.redis.subtitle') }}</p>
    </div>

    <!-- Status badge -->
    <div class="flex items-center gap-3">
      <span :class="redisStatusBadgeClass(redisStatus)">{{ redisStatusLabel(redisStatus) }}</span>
      <span v-if="redisAddress && redisStatus === 'ready'" class="text-[10px] text-gray-400 font-mono">{{ redisAddress }}</span>
    </div>

    <!-- Installing spinner -->
    <div v-if="redisStatus === 'installing'" class="bg-yellow-900/20 border border-yellow-800/40 rounded-md p-3">
      <div class="flex items-center gap-2">
        <div class="w-3 h-3 border-2 border-yellow-400 border-t-transparent rounded-full animate-spin" />
        <span class="text-xs text-yellow-400">{{ t('settings.regionDetail.redis.statusInstalling') }}</span>
      </div>
    </div>

    <!-- Uninstalling spinner -->
    <div v-if="redisStatus === 'uninstalling'" class="bg-orange-900/20 border border-orange-800/40 rounded-md p-3">
      <div class="flex items-center gap-2">
        <div class="w-3 h-3 border-2 border-orange-400 border-t-transparent rounded-full animate-spin" />
        <span class="text-xs text-orange-400">{{ t('settings.regionDetail.redis.statusUninstalling') }}</span>
      </div>
    </div>

    <!-- Ready: readonly info + remove button -->
    <template v-if="redisStatus === 'ready'">
      <div v-if="redisMode" class="text-xs text-gray-400">
        <p class="font-mono bg-gray-900/60 rounded-sm px-3 py-2">
          {{ redisMode === 'managed' ? t('settings.regionDetail.redis.modeManaged') : t('settings.regionDetail.installPopup.redisExisting') }}
        </p>
      </div>
      <button v-if="!readonly" type="button" class="btn-secondary text-xs text-red-400! hover:text-red-300!"
        @click="$emit('remove')">
        {{ t('settings.regionDetail.redis.removeRedis') }}
      </button>
    </template>

    <!-- Configure form: shown when not ready and not installing/uninstalling -->
    <template v-if="!redisStatus || redisStatus === 'error' || redisStatus === 'none'">
      <!-- Mode cards -->
      <div class="grid grid-cols-3 gap-3">
        <button v-for="card in modeCards" :key="card.key" type="button"
          class="p-3 rounded-md border text-left transition-all"
          :class="popupRedisMode === card.key
            ? 'border-brand-500 bg-brand-900/20'
            : 'border-gray-700 bg-gray-900/40 hover:border-gray-600'"
          @click="$emit('update:popupRedisMode', card.key)">
          <p class="text-xs font-medium text-gray-100">{{ t(`settings.regionDetail.redis.${card.labelKey}`) }}</p>
          <p class="text-[10px] text-gray-400 mt-1">{{ t(`settings.regionDetail.redis.${card.descKey}`) }}</p>
        </button>
      </div>

      <!-- External / in-cluster: address + password -->
      <template v-if="popupRedisMode === 'external' || popupRedisMode === 'in-cluster'">
        <div>
          <label class="label"><TermText path="settings.regionDetail.redis.addressLabel" /></label>
          <input type="text" class="input" :value="popupRedisAddress"
            @input="$emit('update:popupRedisAddress', $event.target.value)"
            :placeholder="t('settings.regionDetail.redis.addressPlaceholder')" />
        </div>
        <div>
          <label class="label">{{ t('settings.regionDetail.redis.passwordLabel') }}</label>
          <input type="password" class="input" :value="popupRedisPassword"
            @input="$emit('update:popupRedisPassword', $event.target.value)"
            :placeholder="t('settings.regionDetail.redis.passwordPlaceholder')" />
        </div>
        <!-- Test connection -->
        <div class="flex items-center gap-3">
          <button type="button" class="btn-secondary text-xs" :disabled="!popupRedisAddress || popupRedisTesting"
            @click="$emit('test')">
            {{ popupRedisTesting ? t('settings.regionDetail.installPopup.redisTesting') : t('settings.regionDetail.installPopup.redisTest') }}
          </button>
          <span v-if="popupRedisTestResult === 'ok'" class="text-xs text-green-400">{{ t('settings.regionDetail.installPopup.redisTestOk') }}</span>
          <span v-if="popupRedisTestResult === 'fail'" class="text-xs text-red-400">{{ t('settings.regionDetail.installPopup.redisTestFail') }}</span>
        </div>
      </template>

      <!-- Managed: optional password -->
      <template v-if="popupRedisMode === 'managed'">
        <div>
          <label class="label">{{ t('settings.regionDetail.redis.passwordLabel') }}</label>
          <input type="password" class="input" :value="popupRedisPassword"
            @input="$emit('update:popupRedisPassword', $event.target.value)"
            :placeholder="t('settings.regionDetail.redis.passwordPlaceholder')" />
        </div>
      </template>

      <!-- Configure button -->
      <button v-if="popupRedisMode" type="button" class="btn-primary text-xs" :disabled="saving"
        @click="$emit('configure')">
        {{ saving ? '...' : t('settings.regionDetail.redis.saveRedis') }}
      </button>
    </template>

    <p v-if="redisError" class="text-sm text-red-400 mt-2">{{ redisError }}</p>
  </div>
</template>

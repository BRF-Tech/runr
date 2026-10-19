<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

const sizeFocused = ref(false)

defineProps({
  registryMode:    { type: String, default: 'internal' },
  storageType:     { type: String, default: 'pvc' },
  pvcConfig:       { type: Object, default: () => ({ storageClass: '', size: '10Gi' }) },
  s3Config:        { type: Object, default: () => ({}) },
  externalConfig:  { type: Object, default: () => ({ address: '', username: '', password: '' }) },
  storageClasses:  { type: Array, default: () => [] },
  setupStatus:     { type: String, default: null },
  setupPhase:      { type: String, default: null },
  setupError:      { type: String, default: null },
  isSettingUp:     { type: Boolean, default: false },
  readonly:        { type: Boolean, default: false },
  hidePlaceholderErrors: { type: Boolean, default: false },
  // no StorageClass list (an existing region whose classes could not be read): a text input
  freeStorageClass: { type: Boolean, default: false },
})

defineEmits([
  'update:registryMode', 'update:storageType',
  'update:pvcConfig', 'update:s3Config', 'update:externalConfig',
])

function updatePvc(emit, current, field, value) {
  emit('update:pvcConfig', { ...current, [field]: value })
}
function updateS3(emit, current, field, value) {
  emit('update:s3Config', { ...current, [field]: value })
}
function updateExt(emit, current, field, value) {
  emit('update:externalConfig', { ...current, [field]: value })
}
</script>

<template>
  <div class="space-y-4">
    <h4 class="text-sm font-medium text-gray-100">
      {{ t('settings.regionDetail.registry.title') }}
    </h4>

    <!-- Registry mode selector -->
    <div v-if="!readonly" class="flex gap-3">
      <label class="flex items-center gap-2 cursor-pointer">
        <input type="radio" :value="'internal'" :checked="registryMode === 'internal'"
          @change="$emit('update:registryMode', 'internal')"
          class="accent-brand-500 focus:ring-brand-500 bg-gray-800 border-gray-700" />
        <span class="text-xs text-gray-100">{{ t('settings.regionDetail.registry.internal') }}</span>
      </label>
      <label class="flex items-center gap-2 cursor-pointer">
        <input type="radio" :value="'external'" :checked="registryMode === 'external'"
          @change="$emit('update:registryMode', 'external')"
          class="accent-brand-500 focus:ring-brand-500 bg-gray-800 border-gray-700" />
        <span class="text-xs text-gray-100">{{ t('settings.regionDetail.registry.external') }}</span>
      </label>
    </div>

    <!-- Internal: storage selection -->
    <template v-if="registryMode === 'internal' && !readonly">
      <div class="flex gap-3">
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" :value="'pvc'" :checked="storageType === 'pvc'"
            @change="$emit('update:storageType', 'pvc')"
            class="accent-brand-500 focus:ring-brand-500 bg-gray-800 border-gray-700" />
          <span class="text-xs text-gray-400">PVC</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="radio" :value="'s3'" :checked="storageType === 's3'"
            @change="$emit('update:storageType', 's3')"
            class="accent-brand-500 focus:ring-brand-500 bg-gray-800 border-gray-700" />
          <span class="text-xs text-gray-400">S3</span>
        </label>
      </div>

      <!-- PVC form -->
      <div v-if="storageType === 'pvc'" class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">{{ t('settings.regionDetail.registry.storageClass') }}</label>
          <div v-if="storageClasses.length" class="relative">
            <select class="input appearance-none pr-8" :value="pvcConfig.storageClass"
              @change="updatePvc($emit, pvcConfig, 'storageClass', $event.target.value)">
              <option v-for="sc in storageClasses" :key="sc.name" :value="sc.name">
                {{ sc.name }}{{ sc.is_default ? ' ★' : '' }}
              </option>
            </select>
            <svg class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
          </div>
          <input v-else-if="freeStorageClass" type="text" class="input" :value="pvcConfig.storageClass"
            :placeholder="t('settings.regionDetail.registry.storageClassPlaceholder')"
            @input="updatePvc($emit, pvcConfig, 'storageClass', $event.target.value)" />
          <p v-else-if="!hidePlaceholderErrors" class="text-xs text-red-400 mt-1">{{ t('settings.regionDetail.registry.noStorageClass') }}</p>
        </div>
        <div>
          <label class="label">{{ t('settings.regionDetail.registry.size') }}</label>
          <div class="flex h-9">
            <input type="number" min="1" class="input rounded-r-none border-r-0 h-full"
              :value="parseInt(pvcConfig.size) || 10"
              @input="updatePvc($emit, pvcConfig, 'size', $event.target.value + 'Gi')"
              @focus="sizeFocused = true"
              @blur="sizeFocused = false" />
            <span
              class="flex items-center px-3 bg-gray-800 border border-l-0 rounded-r-lg text-sm text-gray-400 font-mono shrink-0 transition-colors"
              :class="sizeFocused ? 'border-l-0' : 'border-gray-700'"
              :style="sizeFocused ? 'border-color: rgb(var(--brand-500)/ 0.7)' : ''"
            >Gi</span>
          </div>
        </div>
      </div>

      <!-- S3 form -->
      <div v-if="storageType === 's3'" class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">{{ t('settings.regionDetail.registry.s3Endpoint') }}</label>
          <input type="text" class="input" :value="s3Config.endpoint"
            @input="updateS3($emit, s3Config, 'endpoint', $event.target.value)"
            placeholder="https://s3.example.com" />
        </div>
        <div>
          <label class="label">{{ t('settings.regionDetail.registry.s3Bucket') }}</label>
          <input type="text" class="input" :value="s3Config.bucket"
            @input="updateS3($emit, s3Config, 'bucket', $event.target.value)"
            placeholder="runr-registry" />
        </div>
        <div>
          <label class="label">{{ t('settings.regionDetail.registry.s3Region') }}</label>
          <input type="text" class="input" :value="s3Config.region"
            @input="updateS3($emit, s3Config, 'region', $event.target.value)"
            placeholder="us-east-1" />
        </div>
        <div>
          <label class="label">{{ t('settings.regionDetail.registry.s3AccessKey') }}</label>
          <input type="text" class="input" :value="s3Config.access_key"
            @input="updateS3($emit, s3Config, 'access_key', $event.target.value)" />
        </div>
        <div>
          <label class="label">{{ t('settings.regionDetail.registry.s3SecretKey') }}</label>
          <input type="password" class="input" :value="s3Config.secret_key"
            @input="updateS3($emit, s3Config, 'secret_key', $event.target.value)" />
        </div>
      </div>
    </template>

    <!-- External form -->
    <template v-if="registryMode === 'external' && !readonly">
      <div class="space-y-3">
        <div>
          <label class="label"><TermText path="settings.regionDetail.registry.address" /></label>
          <input type="text" class="input" :value="externalConfig.address"
            @input="updateExt($emit, externalConfig, 'address', $event.target.value)"
            placeholder="registry.example.com:5000" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">{{ t('settings.regionDetail.registry.username') }}</label>
            <input type="text" class="input" :value="externalConfig.username"
              @input="updateExt($emit, externalConfig, 'username', $event.target.value)" />
          </div>
          <div>
            <label class="label">{{ t('settings.regionDetail.registry.password') }}</label>
            <input type="password" class="input" :value="externalConfig.password"
              @input="updateExt($emit, externalConfig, 'password', $event.target.value)" />
          </div>
        </div>
      </div>
    </template>

    <!-- Readonly status -->
    <div v-if="readonly && setupStatus === 'ready'" class="bg-green-900/20 border border-green-800/40 rounded-md p-3">
      <p class="text-xs text-green-400">{{ t('settings.regionDetail.registry.ready') }}</p>
    </div>

    <!-- Setup progress -->
    <div v-if="isSettingUp" class="bg-brand-900/20 border border-brand-800/40 rounded-md p-3">
      <div class="flex items-center gap-2">
        <svg class="animate-spin h-4 w-4 text-brand-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <span class="text-xs text-brand-300">
          {{ t(`settings.regionDetail.registry.phase_${setupPhase}`, setupPhase) }}
        </span>
      </div>
    </div>

    <!-- Error -->
    <div v-if="setupError" class="bg-red-900/20 border border-red-800/40 rounded-md p-3">
      <p class="text-xs text-red-400">{{ setupError }}</p>
    </div>
  </div>
</template>

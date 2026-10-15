<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useToast } from '@/composables/useToast'

const props = defineProps({
  regionId:  { type: String, required: true },
  mode:      { type: String, default: null },
  expiresAt: { type: String, default: null },
})

const { t } = useI18n()
const toast = useToast()

const currentMode      = ref(props.mode)
const currentExpiresAt = ref(props.expiresAt)
watch(() => props.mode, (v) => { currentMode.value = v })
watch(() => props.expiresAt, (v) => { currentExpiresAt.value = v })

const formMode    = ref(props.mode ?? 'custom')
const certificate = ref('')
const privateKey  = ref('')
const saving      = ref(false)
const error       = ref(null)
// Set once the API reports a TLSStore "default" RunR did not create; the user must opt in to replace it
const replaceAsked    = ref(false)
const replaceExisting = ref(false)

const expiresLabel = computed(() => {
  if (!currentExpiresAt.value) return null
  try {
    return new Date(currentExpiresAt.value).toLocaleDateString()
  } catch {
    return currentExpiresAt.value
  }
})

const canSave = computed(() => formMode.value === 'console' || (certificate.value.trim() !== '' && privateKey.value.trim() !== ''))

async function save() {
  error.value = null
  saving.value = true
  try {
    const payload = formMode.value === 'console'
      ? { mode: 'console' }
      : { mode: 'custom', certificate: certificate.value, privateKey: privateKey.value }
    if (replaceExisting.value) payload.replace_existing = true

    const { data } = await knativeRegionsApi.updateFunctionTls(props.regionId, payload)

    currentMode.value      = data.region?.function_tls_mode ?? formMode.value
    currentExpiresAt.value = data.region?.function_tls_expires_at ?? null
    certificate.value = ''
    privateKey.value  = ''
    replaceAsked.value    = false
    replaceExisting.value = false
    toast.success(t('settings.regionDetail.functionTls.saved'))
  } catch (err) {
    if (err.response?.data?.errors?.replace_existing) replaceAsked.value = true
    error.value = err.response?.data?.errors
      ? Object.values(err.response.data.errors).flat().join(' ')
      : (err.response?.data?.message ?? err.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mt-6">
    <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-3">
      {{ t('settings.regionDetail.functionTls.section') }}
    </p>
    <div class="rounded-md border border-gray-800 bg-gray-900/40 px-3 py-3 space-y-3">
      <!-- Current status -->
      <div v-if="currentMode" class="flex items-center gap-2 text-xs">
        <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm text-[10px] font-medium" style="background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))">
          {{ t(`settings.regionDetail.functionTls.mode_${currentMode}`) }}
        </span>
        <span v-if="expiresLabel" class="text-gray-400">
          {{ t('settings.regionDetail.functionTls.expires', { date: expiresLabel }) }}
        </span>
      </div>
      <p v-else class="text-xs text-gray-400">{{ t('settings.regionDetail.functionTls.notConfigured') }}</p>

      <!-- Mode toggle -->
      <div class="flex gap-2">
        <button type="button" class="px-2 py-1 rounded-sm text-[10px] transition-colors"
          :class="formMode === 'custom' ? 'border' : 'text-gray-400 hover:text-gray-100 border border-transparent'"
          :style="formMode === 'custom' ? 'background:rgb(var(--brand-600)/0.2);border-color:rgb(var(--brand-500)/0.4);color:rgb(var(--brand-500))' : ''"
          @click="formMode = 'custom'">{{ t('settings.regionDetail.functionTls.modeUpload') }}</button>
        <button type="button" class="px-2 py-1 rounded-sm text-[10px] transition-colors"
          :class="formMode === 'console' ? 'border' : 'text-gray-400 hover:text-gray-100 border border-transparent'"
          :style="formMode === 'console' ? 'background:rgb(var(--brand-600)/0.2);border-color:rgb(var(--brand-500)/0.4);color:rgb(var(--brand-500))' : ''"
          @click="formMode = 'console'">{{ t('settings.regionDetail.functionTls.modeConsole') }}</button>
      </div>

      <!-- Upload mode -->
      <div v-if="formMode === 'custom'" class="space-y-2">
        <div>
          <label class="label">{{ t('settings.regionDetail.functionTls.certificateLabel') }}</label>
          <textarea v-model="certificate" class="input font-mono text-xs w-full" rows="6"
            :placeholder="t('settings.regionDetail.functionTls.certificatePlaceholder')" spellcheck="false" />
        </div>
        <div>
          <label class="label">{{ t('settings.regionDetail.functionTls.privateKeyLabel') }}</label>
          <textarea v-model="privateKey" class="input font-mono text-xs w-full" rows="6"
            :placeholder="t('settings.regionDetail.functionTls.privateKeyPlaceholder')" spellcheck="false" />
        </div>
      </div>
      <p v-else class="text-[10px] text-gray-400">{{ t('settings.regionDetail.functionTls.consoleHint') }}</p>

      <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

      <label v-if="replaceAsked" class="flex items-start gap-2 text-xs text-gray-300">
        <input v-model="replaceExisting" type="checkbox" class="mt-0.5" />
        <span>{{ t('settings.regionDetail.functionTls.replaceExisting') }}</span>
      </label>

      <div class="flex justify-end">
        <button type="button" class="btn-primary text-xs" :disabled="saving || !canSave" @click="save">
          {{ saving ? t('common.saving') : t('settings.regionDetail.functionTls.save') }}
        </button>
      </div>
    </div>
  </div>
</template>

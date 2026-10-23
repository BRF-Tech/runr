<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from 'lucide-vue-next'
import QRCode from 'qrcode'
import { useRecoveryCodesPdf } from '@/composables/useRecoveryCodesPdf'

const { t } = useI18n()
const { downloadPDF } = useRecoveryCodesPdf()
const props = defineProps({
  setupData: { type: Object, default: null },
  saving: Boolean,
  recoveryCodes: { type: Array, default: () => [] },
})
const emit = defineEmits(['confirm', 'close'])

const code = ref('')
const step = computed(() => props.recoveryCodes?.length ? 'done' : 'verify')
const qrDataUrl = ref('')
const secretCopied = ref(false)
const secretFading = ref(false)
let fadeTimer = null
let revertTimer = null
let clipTimer = null

const maskedSecret = computed(() => {
  const s = props.setupData?.secret ?? ''
  if (s.length <= 8) return s
  return s.slice(0, 4) + '••••••••••••' + s.slice(-4)
})

watch(() => props.setupData?.qr_url, async (url) => {
  if (url) qrDataUrl.value = await QRCode.toDataURL(url, { width: 200, margin: 1 })
}, { immediate: true })

function copySecret() {
  const secret = props.setupData?.secret ?? ''
  navigator.clipboard.writeText(secret)
  secretCopied.value = true
  secretFading.value = false
  clearTimeout(fadeTimer); clearTimeout(revertTimer); clearTimeout(clipTimer)
  fadeTimer   = setTimeout(() => { secretFading.value = true }, 2000)
  revertTimer = setTimeout(() => { secretCopied.value = false; secretFading.value = false }, 2700)
  clipTimer   = setTimeout(() => { navigator.clipboard.writeText('') }, 60000)
}

function handleDownload() {
  downloadPDF(props.recoveryCodes)
  emit('close')
}

// Enter while the confirmation runs sends nothing more: every confirm writes new recovery codes
function submit() {
  if (code.value.length === 6 && !props.saving) emit('confirm', code.value)
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="emit('close')">
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 w-full max-w-md">
      <div class="flex items-center justify-between mb-4">
        <h4 class="text-sm font-semibold text-gray-100">{{ t('account.setup2FATitle') }}</h4>
        <button class="text-gray-400 hover:text-gray-100" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <!-- Step: Verify -->
      <template v-if="step === 'verify'">
        <div v-if="props.setupData" class="space-y-4">
          <p class="text-xs text-gray-400">{{ t('account.setup2FAStep1') }}</p>

          <!-- QR Code (frontend generated) -->
          <div class="flex justify-center p-4 bg-white rounded-lg">
            <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR" class="w-48 h-48" />
            <div v-else class="w-48 h-48 flex items-center justify-center text-gray-400 text-xs">...</div>
          </div>

          <div>
            <p class="text-xs text-gray-500 mb-1">{{ t('account.setup2FAManual') }}</p>
            <button
              class="w-full text-left font-mono text-xs text-gray-300 bg-gray-800 px-3 py-2 rounded-sm break-all transition-all duration-700"
              :class="secretCopied
                ? ['text-green-400 bg-green-900/20', secretFading ? 'opacity-0' : 'opacity-100']
                : 'hover:bg-gray-700 cursor-pointer opacity-100'"
              @click="copySecret"
            >
              {{ secretCopied ? t('account.setup2FACopied') : maskedSecret }}
            </button>
          </div>

          <p class="text-xs text-gray-400">{{ t('account.setup2FAStep2') }}</p>
          <input v-model="code" type="text" inputmode="numeric" maxlength="6" class="input font-mono text-center text-lg tracking-widest"
            :placeholder="t('account.verifyCode')" @keyup.enter="submit" />

          <div class="flex gap-2">
            <button class="btn-primary text-xs" :disabled="code.length !== 6 || props.saving" @click="submit">
              {{ props.saving ? t('common.saving') : t('account.verifyAndEnable') }}
            </button>
            <button class="btn-secondary text-xs" @click="emit('close')">{{ t('common.cancel') }}</button>
          </div>
        </div>
        <div v-else class="py-8 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
      </template>

      <!-- Step: Recovery codes -->
      <template v-if="step === 'done'">
        <div class="space-y-4">
          <p class="text-xs text-gray-400">{{ t('account.recoveryCodesDesc') }}</p>
          <div class="grid grid-cols-2 gap-1">
            <code v-for="c in props.recoveryCodes" :key="c" class="text-xs font-mono text-gray-300 bg-gray-800 px-2 py-1 rounded-sm">
              {{ c }}
            </code>
          </div>
          <button class="btn-primary text-xs w-full justify-center" @click="handleDownload">{{ t('account.downloadRecoveryCodes') }}</button>
        </div>
      </template>
    </div>
  </div>
</template>

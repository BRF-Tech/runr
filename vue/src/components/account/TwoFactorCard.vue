<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldCheck } from 'lucide-vue-next'
import TwoFactorSetupModal from './TwoFactorSetupModal.vue'
import { useRecoveryCodesPdf } from '@/composables/useRecoveryCodesPdf'

const { t } = useI18n()
const { downloadPDF } = useRecoveryCodesPdf()
const props = defineProps({
  enabled: Boolean,
  setupData: { type: Object, default: null },
  recoveryCodes: { type: Array, default: () => [] },
  saving: Boolean,
})
const emit = defineEmits(['setup', 'confirm', 'disable', 'regenerate'])

const showSetupModal  = ref(false)
const showDisable     = ref(false)
const disablePassword = ref('')

async function startSetup() {
  emit('setup')
  showSetupModal.value = true
}

function onConfirmed(code) {
  emit('confirm', code)
}

function closeSetup() {
  showSetupModal.value = false
}

async function submitDisable() {
  emit('disable', disablePassword.value)
  showDisable.value = false
  disablePassword.value = ''
}
</script>

<template>
  <div class="card p-6">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <ShieldCheck class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('account.twoFactorTitle') }}</h3>
    </div>

    <p class="text-xs text-gray-400 mb-4">{{ t('account.twoFactorDesc') }}</p>

    <div class="flex items-center gap-3 mb-4">
      <span class="text-xs font-medium px-2 py-0.5 rounded"
        :class="props.enabled ? 'bg-green-900/30 text-green-400 border border-green-800' : 'bg-gray-800 text-gray-400 border border-gray-700'">
        {{ props.enabled ? t('account.twoFactorEnabled') : t('account.twoFactorDisabled') }}
      </span>
    </div>

    <!-- Enable button -->
    <div v-if="!props.enabled" class="flex gap-2">
      <button class="btn-primary text-xs" :disabled="props.saving" @click="startSetup">
        {{ t('account.enable2FA') }}
      </button>
    </div>

    <!-- Enabled: show disable + regenerate -->
    <div v-else class="space-y-3">
      <div class="flex gap-2">
        <button class="btn-secondary text-xs" @click="emit('regenerate')">
          {{ t('account.regenerateCodes') }}
        </button>
        <button class="btn-secondary text-xs text-red-400 hover:text-red-300" @click="showDisable = true">
          {{ t('account.disable2FA') }}
        </button>
      </div>

      <!-- Recovery codes (shown after enable or regenerate) -->
      <div v-if="props.recoveryCodes?.length" class="rounded-md border border-gray-700 bg-gray-900/60 p-4 space-y-3">
        <p class="text-xs text-gray-400">{{ t('account.recoveryCodesDesc') }}</p>
        <div class="grid grid-cols-2 gap-1">
          <code v-for="code in props.recoveryCodes" :key="code" class="text-xs font-mono text-gray-300 bg-gray-800 px-2 py-1 rounded">
            {{ code }}
          </code>
        </div>
        <button class="btn-primary text-xs w-full justify-center" @click="downloadPDF(props.recoveryCodes)">
          {{ t('account.downloadRecoveryCodes') }}
        </button>
      </div>
    </div>

    <!-- Disable confirmation -->
    <div v-if="showDisable" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="showDisable = false">
      <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 w-full max-w-sm">
        <h4 class="text-sm font-semibold text-gray-100 mb-2">{{ t('account.disable2FA') }}</h4>
        <p class="text-xs text-gray-400 mb-4">{{ t('account.disable2FAConfirm') }}</p>
        <input v-model="disablePassword" type="password" class="input mb-4" :placeholder="t('account.deletePassword')" autocomplete="current-password" />
        <div class="flex gap-2">
          <button class="btn-primary text-xs bg-red-600 hover:bg-red-500" :disabled="!disablePassword" @click="submitDisable">
            {{ t('account.disable2FA') }}
          </button>
          <button class="btn-secondary text-xs" @click="showDisable = false">{{ t('common.cancel') }}</button>
        </div>
      </div>
    </div>

    <!-- Setup modal -->
    <TwoFactorSetupModal
      v-if="showSetupModal"
      :setup-data="props.setupData"
      :saving="props.saving"
      :recovery-codes="props.recoveryCodes"
      @confirm="onConfirmed"
      @close="closeSetup"
    />
  </div>
</template>

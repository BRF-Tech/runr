<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Lock } from 'lucide-vue-next'

const { t } = useI18n()
const emit  = defineEmits(['change'])
const props = defineProps({ saving: Boolean, error: { type: String, default: null } })

const current  = ref('')
const newPw    = ref('')
const confirmPw = ref('')

function submit() {
  emit('change', current.value, newPw.value, confirmPw.value)
  current.value  = ''
  newPw.value    = ''
  confirmPw.value = ''
}
</script>

<template>
  <div class="card p-6">
    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-gray-800">
      <Lock class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('account.passwordTitle') }}</h3>
    </div>

    <div class="space-y-4">
      <div>
        <label class="label">{{ t('account.currentPassword') }}</label>
        <input v-model="current" type="password" class="input" autocomplete="current-password" />
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="label">{{ t('account.newPassword') }}</label>
          <input v-model="newPw" type="password" class="input" autocomplete="new-password" />
        </div>
        <div>
          <label class="label">{{ t('account.confirmPassword') }}</label>
          <input v-model="confirmPw" type="password" class="input" autocomplete="new-password" />
        </div>
      </div>

      <p v-if="props.error" class="text-xs text-red-400">{{ props.error }}</p>

      <button class="btn-primary text-xs" :disabled="props.saving || !current || !newPw || !confirmPw" @click="submit">
        {{ props.saving ? t('common.saving') : t('account.changePassword') }}
      </button>
    </div>
  </div>
</template>

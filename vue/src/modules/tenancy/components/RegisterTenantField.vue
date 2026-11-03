<script setup>
import { useI18n } from 'vue-i18n'

const model = defineModel({ type: String, default: '' })
const { t } = useI18n()
</script>

<template>
  <div>
    <label class="login-label block text-xs font-bold mb-2 uppercase tracking-widest">
      {{ t('tenancy.workspaceName') }}
    </label>
    <input
      v-model="model"
      type="text"
      class="login-input w-full px-4 py-4 rounded-md text-base transition"
      :placeholder="t('tenancy.workspaceNamePlaceholder')"
      autocomplete="organization"
      required
    />
    <p class="text-xs mt-1.5" style="color:rgb(var(--surface-light-600) / .7)">{{ t('tenancy.workspaceHint') }}</p>
  </div>
</template>

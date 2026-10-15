<script setup>
// Write-only secret field (payment gateway keys, webhook secrets). The API never returns a
// secret — only whether one is stored (`set`). v-model: undefined = keep, '' = clear,
// any other string = replace.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { KeyRound, Trash2 } from 'lucide-vue-next'

const props = defineProps({
  label:      { type: String, required: true },
  set:        { type: Boolean, default: false },
  modelValue: { type: String, default: undefined },
  testId:     { type: String, default: 'secret' },
})
const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const text = ref('')
const cleared = computed(() => props.modelValue === '')

watch(() => props.modelValue, (v) => { if (v === undefined) text.value = '' })

function onInput(value) {
  text.value = value
  emit('update:modelValue', value.trim() === '' ? undefined : value.trim())
}

function clear() {
  text.value = ''
  emit('update:modelValue', '')
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between">
      <label class="label">{{ label }}</label>
      <span class="text-xs flex items-center gap-1" :class="set && !cleared ? 'text-emerald-400' : 'text-gray-400'" :data-test="`${testId}-state`">
        <KeyRound class="w-3 h-3" />
        {{ cleared ? t('whiteLabel.secret.willClear') : set ? t('whiteLabel.secret.set') : t('whiteLabel.secret.notSet') }}
      </span>
    </div>
    <div class="flex gap-2">
      <input :value="text" type="password" autocomplete="new-password" class="input font-mono"
        :placeholder="set ? t('whiteLabel.secret.replacePlaceholder') : t('whiteLabel.secret.enterPlaceholder')"
        :data-test="testId" @input="onInput($event.target.value)" />
      <button v-if="set" type="button" class="btn-secondary" :data-test="`${testId}-clear`" @click="clear">
        <Trash2 class="w-4 h-4" />{{ t('whiteLabel.secret.clear') }}
      </button>
    </div>
  </div>
</template>

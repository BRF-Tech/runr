<script setup>
// A write-only credential of a runtime source (docs/runtimes.md#private-sources): the API never
// sends a value back, only whether one is set (and a token's last characters). A stored one is
// kept, replaced or removed; the parent sends the field only when it is replaced (the value) or
// removed (null).
//   modelValue  { mode: 'keep' | 'replace' | 'remove', value: string }
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue:  { type: Object, required: true },
  label:       { type: String, required: true },
  // a value is stored
  set:         { type: Boolean, default: false },
  // what the API tells of the stored value (a token's last four characters)
  hint:        { type: String, default: null },
  multiline:   { type: Boolean, default: false },
  placeholder: { type: String, default: '' },
  error:       { type: String, default: null },
  field:       { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

function setMode(mode) {
  emit('update:modelValue', { mode, value: mode === 'replace' ? props.modelValue.value : '' })
}

function setValue(value) {
  emit('update:modelValue', { mode: 'replace', value })
}
</script>

<template>
  <div :data-secret="field">
    <label class="label">{{ label }}</label>
    <!-- Stored: kept unless replaced or removed -->
    <div v-if="set && modelValue.mode !== 'replace'" class="flex flex-wrap items-center gap-2 text-xs">
      <span v-if="modelValue.mode === 'keep'" class="px-2 py-1 rounded-md border border-emerald-800/50 bg-emerald-900/20 text-emerald-400" data-test="secret-set">
        {{ hint ? t('runtimes.sources.secret.setHint', { hint }) : t('runtimes.sources.secret.set') }}
      </span>
      <span v-else class="px-2 py-1 rounded-md border border-red-800/50 bg-red-900/20 text-red-400" data-test="secret-removed">{{ t('runtimes.sources.secret.removed') }}</span>
      <button v-if="modelValue.mode === 'keep'" type="button" class="btn-secondary text-xs py-1!" data-test="secret-replace" @click="setMode('replace')">{{ t('runtimes.sources.secret.replace') }}</button>
      <button v-if="modelValue.mode === 'keep'" type="button" class="btn-secondary text-xs py-1! text-red-400" data-test="secret-remove" @click="setMode('remove')">{{ t('runtimes.sources.secret.remove') }}</button>
      <button v-else type="button" class="btn-secondary text-xs py-1!" data-test="secret-undo" @click="setMode('keep')">{{ t('runtimes.sources.secret.undo') }}</button>
    </div>
    <template v-else>
      <textarea
        v-if="multiline"
        :value="modelValue.value"
        class="input font-mono text-xs h-24"
        :placeholder="placeholder"
        spellcheck="false"
        autocomplete="off"
        :data-test="`secret-input-${field}`"
        @input="setValue($event.target.value)"
      />
      <input
        v-else
        type="password"
        :value="modelValue.value"
        class="input font-mono"
        :placeholder="placeholder"
        autocomplete="new-password"
        :data-test="`secret-input-${field}`"
        @input="setValue($event.target.value)"
      />
      <button v-if="set" type="button" class="mt-1 text-[11px] text-gray-400 hover:text-gray-100" data-test="secret-undo" @click="setMode('keep')">{{ t('runtimes.sources.secret.keep') }}</button>
    </template>
    <p v-if="error" class="text-[11px] text-red-400 mt-1">{{ error }}</p>
  </div>
</template>

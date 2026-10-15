<script setup>
// Switch with a label and an optional hint (Git tab settings)
defineProps({
  modelValue: { type: Boolean, default: false },
  label:      { type: String, required: true },
  hint:       { type: String, default: '' },
  disabled:   { type: Boolean, default: false },
  testId:     { type: String, default: '' },
})
defineEmits(['update:modelValue'])
</script>

<template>
  <div class="flex items-start gap-3">
    <button
      type="button"
      role="switch"
      :aria-checked="modelValue"
      :aria-label="label"
      :data-testid="testId || undefined"
      :disabled="disabled"
      class="relative mt-0.5 inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
      :class="modelValue ? 'bg-brand-500' : 'bg-gray-700'"
      @click="$emit('update:modelValue', !modelValue)"
    >
      <span class="pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow-sm transition" :class="modelValue ? 'translate-x-4' : 'translate-x-0'" />
    </button>
    <div class="min-w-0">
      <p class="text-sm text-gray-200">{{ label }}</p>
      <p v-if="hint" class="text-xs text-gray-500 mt-0.5">{{ hint }}</p>
    </div>
  </div>
</template>

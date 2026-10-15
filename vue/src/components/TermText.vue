<script setup>
// A translated text inside an upper-cased label or heading. CSS text-transform follows the
// element's language, so under the Turkish locale the English terms the message keeps would
// read KNATİVE, MİB, DOMAİN; they are rendered as <span lang="en"> (utils/termCase.js).
// Use instead of {{ t(path) }} where the Turkish message keeps an English term with an i
// (tests/i18n/uppercaseTerms.spec.js lists the places that need it).
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { termSegments } from '@/utils/termCase'

const props = defineProps({
  path:   { type: String, required: true },
  params: { type: Object, default: () => ({}) },
})

const { t, locale } = useI18n()

const segments = computed(() => {
  const text = t(props.path, props.params)
  if (locale.value === 'en') return [{ text, en: false }]
  return termSegments(text, t(props.path, props.params, { locale: 'en' }))
})
</script>

<template>
  <template v-for="(s, i) in segments" :key="i"><span v-if="s.en" lang="en">{{ s.text }}</span><template v-else>{{ s.text }}</template></template>
</template>

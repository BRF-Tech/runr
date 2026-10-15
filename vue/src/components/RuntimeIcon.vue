<script setup>
import { computed } from 'vue'
import { Code2 } from 'lucide-vue-next'
import { runtimeIcon } from '@/utils/runtimes'

const props = defineProps({
  runtime: { type: String, default: null },
  size: { type: Number, default: 14 },
})

const src = computed(() => runtimeIcon(props.runtime))
</script>

<template>
  <img v-if="src" :src="src" :width="size" :height="size" :alt="runtime" class="inline-block shrink-0 object-contain" />
  <Code2 v-else-if="runtime" :size="size" class="inline-block shrink-0 text-gray-400" :aria-label="runtime" />
</template>

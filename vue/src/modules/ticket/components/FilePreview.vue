<script setup>
import { ref, watch, onUnmounted } from 'vue'
import { Paperclip, X } from 'lucide-vue-next'

const props = defineProps({
  files: { type: Array, default: () => [] },
})
const emit = defineEmits(['remove'])

const previews = ref([])

function isImage(file) {
  return file.type?.startsWith('image/')
}

function buildPreviews(files) {
  previews.value.forEach(p => { if (p.url) URL.revokeObjectURL(p.url) })
  previews.value = files.map(f => ({
    name: f.name,
    isImage: isImage(f),
    url: isImage(f) ? URL.createObjectURL(f) : null,
  }))
}

watch(() => props.files, buildPreviews, { immediate: true, deep: true })

onUnmounted(() => {
  previews.value.forEach(p => { if (p.url) URL.revokeObjectURL(p.url) })
})
</script>

<template>
  <div v-if="previews.length" class="flex flex-wrap gap-2">
    <div v-for="(p, i) in previews" :key="i"
      class="relative group flex items-center gap-1.5 bg-gray-800 rounded-sm px-2 py-1.5 border border-gray-700">
      <img v-if="p.isImage" :src="p.url" :alt="p.name" class="w-8 h-8 rounded-sm object-cover" />
      <Paperclip v-else class="w-4 h-4 text-gray-500" />
      <span class="text-[10px] text-gray-300 max-w-[120px] truncate">{{ p.name }}</span>
      <button class="ml-1 text-gray-500 hover:text-red-400 transition-colors" @click="emit('remove', i)">
        <X class="w-3 h-3" />
      </button>
    </div>
  </div>
</template>

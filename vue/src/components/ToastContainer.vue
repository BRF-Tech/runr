<script setup>
import { ref, watch } from 'vue'
import { useToast } from '@/composables/useToast'

const { toasts, remove } = useToast()
const visible = ref(new Set())

watch(toasts, (list) => {
  list.forEach((toast) => {
    if (visible.value.has(toast.id)) return

    // enter
    requestAnimationFrame(() => {
      visible.value.add(toast.id)
      visible.value = new Set(visible.value)
    })

    // exit
    setTimeout(() => {
      visible.value.delete(toast.id)
      visible.value = new Set(visible.value)
      setTimeout(() => remove(toast.id), 300)
    }, toast.duration)
  })
}, { deep: true })
</script>

<template>
  <Teleport to="body">
    <div class="fixed top-4 right-4 z-100 flex flex-col gap-2 pointer-events-none">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="transition-all duration-300 pointer-events-auto"
        :class="visible.has(toast.id) ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-2'"
      >
        <div
          class="rounded-md border px-4 py-2.5 text-sm shadow-lg backdrop-blur-xs"
          :class="toast.type === 'error'
            ? 'bg-red-950/90 border-red-800/60 text-red-300'
            : 'bg-emerald-950/90 border-emerald-800/60 text-emerald-300'"
        >
          {{ toast.message }}
        </div>
      </div>
    </div>
  </Teleport>
</template>

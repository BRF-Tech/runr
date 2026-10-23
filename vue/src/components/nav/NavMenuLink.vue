<script setup>
// One link of the navigation (mega menu, mobile drawer, user menu): icon, label and the
// one-line description. Marked as the current page when the route shows it.
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Circle } from 'lucide-vue-next'
import { isLinkActive, linkLocation } from '@/navigation/menu'

const props = defineProps({
  link:    { type: Object, required: true },
  // smaller rows without the description (user menu)
  compact: { type: Boolean, default: false },
})

const { t } = useI18n()
const route = useRoute()
const active = computed(() => isLinkActive(props.link, route))
</script>

<template>
  <RouterLink
    :to="linkLocation(link)"
    data-nav-link
    :data-testid="`nav-link-${link.key}`"
    :data-tour="link.dataTour || undefined"
    :aria-current="active ? 'page' : undefined"
    class="group flex gap-3 rounded-lg transition-colors focus:outline-hidden focus-visible:ring-2 focus-visible:ring-brand-500/60"
    :class="[
      compact ? 'items-center px-3 py-2' : 'items-start px-3 py-2.5',
      active ? 'bg-brand-500/10 text-brand-500' : 'text-gray-300 hover:bg-gray-800/70 hover:text-gray-100 focus-visible:bg-gray-800/70',
    ]"
  >
    <span
      class="shrink-0 flex items-center justify-center rounded-md transition-colors"
      :class="[
        compact ? 'w-5 h-5' : 'w-8 h-8 border',
        compact ? '' : (active ? 'border-brand-500/40 bg-brand-500/10' : 'border-gray-800 bg-gray-900 group-hover:border-gray-700'),
      ]"
      aria-hidden="true"
    >
      <component :is="link.icon || Circle" class="w-4 h-4" :class="active ? 'text-brand-500' : 'text-gray-400 group-hover:text-brand-500'" />
    </span>
    <span class="min-w-0">
      <span class="block text-sm font-medium leading-5">{{ t(link.labelKey) }}</span>
      <span v-if="!compact && link.descKey" class="block text-xs leading-4 text-gray-500 mt-0.5">{{ t(link.descKey) }}</span>
    </span>
  </RouterLink>
</template>

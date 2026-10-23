<script setup>
// The mega menu of a main-menu group: the group's title and description, then its sections,
// each a labelled column of links with a one-line description.
import { useI18n } from 'vue-i18n'
import NavMenuLink from './NavMenuLink.vue'

defineProps({
  group: { type: Object, required: true },
})

const { t } = useI18n()
</script>

<template>
  <div
    :id="`nav-panel-${group.key}`"
    role="region"
    :aria-labelledby="`nav-trigger-${group.key}`"
    :data-testid="`nav-panel-${group.key}`"
    class="absolute inset-x-0 top-full z-50 border-b border-gray-800 bg-gray-950 shadow-2xl"
  >
    <div class="px-6 py-6 flex gap-10 max-h-[calc(100vh-4rem)] overflow-y-auto">
      <div class="hidden xl:block w-60 shrink-0">
        <div class="w-10 h-10 rounded-lg flex items-center justify-center mb-3" style="background:rgb(var(--brand-500)/0.1)" aria-hidden="true">
          <component :is="group.icon" class="w-5 h-5" style="color:rgb(var(--brand-500))" />
        </div>
        <p class="text-base font-semibold text-gray-100">{{ t(group.labelKey) }}</p>
        <p class="text-sm text-gray-400 mt-1 leading-relaxed">{{ t(group.descKey) }}</p>
      </div>

      <div class="flex-1 flex flex-wrap gap-x-8 gap-y-6">
        <section
          v-for="section in group.sections"
          :key="section.key"
          class="w-72 min-w-0"
          :aria-labelledby="`nav-section-${group.key}-${section.key}`"
          :data-nav-section="section.key"
        >
          <h3 :id="`nav-section-${group.key}-${section.key}`" class="px-3 mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-500">
            {{ t(section.labelKey) }}
          </h3>
          <ul class="space-y-0.5" role="list">
            <li v-for="link in section.links" :key="link.key">
              <NavMenuLink :link="link" />
            </li>
          </ul>
        </section>
      </div>
    </div>
  </div>
</template>

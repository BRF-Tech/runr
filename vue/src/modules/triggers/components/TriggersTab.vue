<script setup>
// Project "Triggers" tab: the trigger list and, opened from a webhook / platform_event row, the
// trigger's inbox (stored events, replay).
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Zap } from 'lucide-vue-next'
import TriggerList from './TriggerList.vue'
import TriggerInbox from './TriggerInbox.vue'

defineProps({
  project: { type: Object, required: true },
})

const { t } = useI18n()
const inbox = ref(null)   // the trigger whose inbox is open
</script>

<template>
  <div class="py-5 space-y-4" data-test="triggers-tab">
    <TriggerInbox v-if="inbox" :project="project" :trigger="inbox" @back="inbox = null" />

    <template v-else>
      <div>
        <div class="flex items-center gap-2">
          <Zap class="w-4 h-4 text-amber-300" />
          <h3 class="text-sm font-semibold text-gray-100">{{ t('triggers.title') }}</h3>
        </div>
        <p class="text-xs text-gray-400 mt-1 max-w-3xl">{{ t('triggers.intro') }}</p>
      </div>
      <TriggerList :project="project" @open-inbox="inbox = $event" />
    </template>
  </div>
</template>

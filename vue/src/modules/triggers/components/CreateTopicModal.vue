<script setup>
// New topic of the organization (POST /topics): name, a region with Redis, description.
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import { useTopicsStore } from '../stores/topics'
import { TOPIC_NAME_PATTERN, apiFieldErrors, errorMessage, topicRegionAvailable } from '../utils/triggers'

const emit = defineEmits(['created', 'close'])

const { t } = useI18n()
const settingsStore = useSettingsStore()
const store = useTopicsStore()

const regions = computed(() => (settingsStore.data.regions ?? []).filter(r => r.is_active !== false))
const firstAvailable = regions.value.find(topicRegionAvailable)

const name        = ref('')
const regionId    = ref(firstAvailable?.id ?? '')
const description = ref('')
const saving      = ref(false)
const errors      = ref({})
const message     = ref(null)

// The settings may load after the modal opened: preselect the first region with Redis then
watch(regions, (list) => {
  if (!regionId.value) regionId.value = list.find(topicRegionAvailable)?.id ?? ''
})

const nameValid = computed(() => !name.value.trim() || TOPIC_NAME_PATTERN.test(name.value.trim()))
const canSubmit = computed(() => !saving.value && !!name.value.trim() && nameValid.value && !!regionId.value)

async function submit() {
  if (!canSubmit.value) return
  saving.value  = true
  errors.value  = {}
  message.value = null
  try {
    const topic = await store.create({ name: name.value, knative_region_id: regionId.value, description: description.value })
    emit('created', topic)
  } catch (e) {
    errors.value  = apiFieldErrors(e?.response?.data?.errors)
    message.value = errorMessage(e, t('common.error'))
  } finally {
    saving.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !saving.value) emit('close')
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  if (!settingsStore.loaded) settingsStore.fetch().catch(() => {})
})
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-[2px] p-4" data-test="create-topic-modal">
    <form class="bg-gray-900 border border-gray-800 rounded-xl shadow-2xl w-full max-w-lg" novalidate @submit.prevent="submit">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('triggers.topics.createTitle') }}</h3>
        <button type="button" class="text-gray-400 hover:text-gray-100 text-sm" :disabled="saving" :aria-label="t('triggers.topics.close')" @click="emit('close')">✕</button>
      </div>
      <div class="px-6 py-5 space-y-4">
        <div>
          <label class="label">{{ t('triggers.topics.name') }}</label>
          <input v-model="name" class="input font-mono text-xs" placeholder="orders.created" maxlength="100" data-field="name" />
          <p v-if="!nameValid" class="text-[11px] text-red-400 mt-1">{{ t('triggers.topics.nameInvalid') }}</p>
          <p v-else-if="errors.name" class="text-[11px] text-red-400 mt-1">{{ errors.name }}</p>
          <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.topics.nameHint') }}</p>
        </div>
        <div>
          <label class="label">{{ t('triggers.topics.region') }}</label>
          <select v-model="regionId" class="input text-xs" data-field="region">
            <option value="" disabled>{{ t('triggers.topics.regionPlaceholder') }}</option>
            <option v-for="r in regions" :key="r.id" :value="r.id" :disabled="!topicRegionAvailable(r)">
              {{ topicRegionAvailable(r) ? r.name : t('triggers.topics.regionNoRedis', { name: r.name }) }}
            </option>
          </select>
          <p v-if="errors.knative_region_id" class="text-[11px] text-red-400 mt-1">{{ errors.knative_region_id }}</p>
        </div>
        <div>
          <label class="label">{{ t('triggers.topics.description') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
          <input v-model="description" class="input text-xs" maxlength="500" data-field="description" />
        </div>
        <p v-if="message" class="text-xs text-red-400" data-test="create-topic-error">{{ message }}</p>
      </div>
      <div class="flex justify-end gap-2 px-6 py-4 border-t border-gray-800">
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="saving" @click="emit('close')">{{ t('triggers.topics.cancel') }}</button>
        <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="!canSubmit" data-test="create-topic-submit">{{ t('triggers.topics.create') }}</button>
      </div>
    </form>
  </div>
</template>

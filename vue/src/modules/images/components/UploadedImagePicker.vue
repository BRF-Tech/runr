<script setup>
// The image of a CaaS project: a registry reference, or one of the organization's uploaded
// container images in the project's region (docs/images.md) — the uploaded image is pinned by
// digest in the region registry.
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { imagesApi } from '../api'
import { usePermissionsStore } from '@/stores/permissions'

const { t } = useI18n()
const perms = usePermissionsStore()

const props = defineProps({
  modelValue:  { type: String, default: '' },
  regionId:    { type: String, default: null },
  placeholder: { type: String, default: '' },
  label:       { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const canView = computed(() => perms.can('images.view'))
const source  = ref('registry')   // registry | uploaded
const images  = ref([])
const loading = ref(false)

const selected = computed(() => images.value.find(i => i.image_ref === props.modelValue) ?? null)

async function load() {
  if (!canView.value || !props.regionId) {
    images.value = []
    return
  }
  loading.value = true
  try {
    const { data } = await imagesApi.list({ knative_region_id: props.regionId, kind: 'image' })
    images.value = data.data ?? []
    if (images.value.some(i => i.image_ref === props.modelValue)) source.value = 'uploaded'
  } catch {
    images.value = []
  } finally {
    loading.value = false
  }
}

watch(() => props.regionId, load, { immediate: true })

function setSource(value) {
  if (source.value === value) return
  source.value = value
  emit('update:modelValue', value === 'uploaded' ? (images.value[0]?.image_ref ?? '') : '')
}
</script>

<template>
  <div data-testid="uploaded-image-picker">
    <div class="flex items-center justify-between mb-1">
      <label class="label mb-0!">{{ label || t('create.fields.image') }}</label>
      <div v-if="canView" class="flex gap-1" role="group" :aria-label="t('images.picker.source')">
        <button type="button" class="px-2 py-0.5 rounded-sm text-[11px] border"
          :class="source === 'registry' ? 'border-sky-600 text-sky-300' : 'border-gray-700 text-gray-400'"
          data-testid="image-source-registry" @click="setSource('registry')">{{ t('images.picker.registry') }}</button>
        <button type="button" class="px-2 py-0.5 rounded-sm text-[11px] border"
          :class="source === 'uploaded' ? 'border-sky-600 text-sky-300' : 'border-gray-700 text-gray-400'"
          data-testid="image-source-uploaded" @click="setSource('uploaded')">{{ t('images.picker.uploaded') }}</button>
      </div>
    </div>

    <input v-if="source === 'registry'" :value="modelValue" class="input font-mono" :placeholder="placeholder"
      data-testid="image-ref-input" @input="emit('update:modelValue', $event.target.value)" />

    <template v-else>
      <p v-if="!regionId" class="text-[11px] text-gray-400">{{ t('images.picker.pickRegion') }}</p>
      <p v-else-if="!loading && !images.length" class="text-[11px] text-gray-400" data-testid="uploaded-image-none">
        {{ t('images.picker.none') }} {{ t('images.picker.manage') }}
      </p>
      <select v-else :value="modelValue" class="input font-mono" data-testid="uploaded-image-select"
        @change="emit('update:modelValue', $event.target.value)">
        <option value="" disabled>{{ t('images.picker.select') }}</option>
        <option v-for="i in images" :key="i.id" :value="i.image_ref">{{ i.name }} · {{ (i.platforms ?? []).join(', ') }}</option>
      </select>
      <p v-if="selected" class="text-[10px] text-gray-500 font-mono mt-1 truncate">{{ t('images.picker.pinned', { digest: selected.digest }) }}</p>
    </template>
  </div>
</template>

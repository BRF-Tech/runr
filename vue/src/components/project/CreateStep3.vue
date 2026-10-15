<script setup>
import { useI18n } from 'vue-i18n'
import RuntimeSelector from '@/components/project/RuntimeSelector.vue'
import RuntimeTemplatePreview from '@/components/project/RuntimeTemplatePreview.vue'
import TriggerConfig from '@/components/project/TriggerConfig.vue'
import EndpointTypeSelector from '@/components/project/EndpointTypeSelector.vue'
import UploadedImagePicker from '@/modules/images/components/UploadedImagePicker.vue'

const { t } = useI18n()

defineProps({
  form: { type: Object, required: true },
  runtimes: { type: Array, required: true },
  runtimeVersions: { type: Array, required: true },
  selectedRegion: { type: Object, default: null },
  selectedRegionName: { type: String, default: '' },
  username: { type: String, default: '' },
  canNext: { type: Boolean, default: false },
})

defineEmits(['next', 'back'])
</script>

<template>
  <div>
    <h2 class="text-lg font-semibold mb-1">{{ t('create.step3.title') }}</h2>
    <p class="text-sm text-gray-400 mb-5">{{ t('create.step3.subtitle') }}</p>

    <div class="space-y-5 max-h-[58vh] overflow-y-auto pr-1">
      <RuntimeSelector
        v-if="form.service_type !== 'caas'"
        data-tour="create-lang"
        :runtimes="runtimes"
        :runtime-versions="runtimeVersions"
        :selected-runtime="form.runtime"
        :selected-version="form.runtime_version"
        :trigger-type="form.config.trigger_type"
        @update:runtime="form.runtime = $event"
        @update:version="form.runtime_version = $event"
      />
      <RuntimeTemplatePreview
        v-if="form.service_type !== 'caas'"
        :runtime="form.runtime"
        :service-type="form.service_type"
      />

      <section v-if="form.service_type === 'caas'" data-tour="create-container">
        <p class="section-title">{{ t('create.sections.container') }}</p>
        <div class="flex gap-3">
          <div class="flex-1">
            <!-- a registry reference, or an uploaded image of the region (docs/images.md) -->
            <UploadedImagePicker
              v-model="form.config.image"
              :region-id="selectedRegion?.id ?? null"
              :placeholder="t('create.placeholders.image')"
            />
          </div>
          <div v-if="form.config.trigger_type !== 'schedule'" class="w-28">
            <label class="label">{{ t('create.fields.port') }}</label>
            <input v-model.number="form.config.port" type="number" min="1" max="65535" class="input font-mono" placeholder="80" />
          </div>
        </div>
        <div class="mt-3">
          <label class="label">{{ t('create.fields.command') }} <span class="normal-case text-gray-400">{{ t('create.fields.commandOptional') }}</span></label>
          <input v-model="form.config.command" class="input font-mono" :placeholder="t('create.placeholders.command')" />
        </div>
      </section>

      <TriggerConfig
        data-tour="create-trigger"
        v-model:trigger-type="form.config.trigger_type"
        v-model:schedule="form.config.schedule"
        v-model:queue-stream="form.config.queue_stream"
        :region="selectedRegion"
      />

      <EndpointTypeSelector
        v-if="form.config.trigger_type === 'http'"
        v-model:endpoint-type="form.endpoint_type"
        v-model:gateway-path="form.gateway_path"
        v-model:gateway-methods="form.gateway_methods"
        :project-slug="form.name.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')"
        :region-name="selectedRegionName"
        :username="username"
      />
    </div>

    <div class="flex justify-between pt-4 mt-2 border-t border-gray-800/60">
      <button class="btn-secondary" @click="$emit('back')">{{ t('common.back') }}</button>
      <button class="btn-primary" :disabled="!canNext" @click="$emit('next')">{{ t('common.next') }}</button>
    </div>
  </div>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>

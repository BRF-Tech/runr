<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ResourcesEditor from '@/components/project/ResourcesEditor.vue'
import ScaleConfig from '@/components/project/ScaleConfig.vue'
import EnvVarsEditor from '@/components/project/EnvVarsEditor.vue'
import SecretEnvVarsEditor from '@/components/project/SecretEnvVarsEditor.vue'
import { parseEnvVars } from '@/composables/useProjectForm'
import GpuConfig from '@/components/project/GpuConfig.vue'

const { t } = useI18n()

const props = defineProps({
  form: { type: Object, required: true },
  canNext: { type: Boolean, default: false },
  maxTimeout: { type: Number, default: null },
  loading: { type: Boolean, default: false },
  error: { type: String, default: null },
})

defineEmits(['back', 'submit'])

// A name is either a plain or a secret env var
const plainEnvNames = computed(() => Object.keys(parseEnvVars(props.form.config.env_vars) ?? {}))
</script>

<template>
  <div>
    <h2 class="text-lg font-semibold mb-1">{{ t('create.step4.title') }}</h2>
    <p class="text-sm text-gray-400 mb-5">{{ t('create.step4.subtitle') }}</p>

    <div class="space-y-5 max-h-[58vh] overflow-y-auto pr-1">
      <ResourcesEditor
        data-tour="create-resources"
        v-model:cpu="form.config.cpu"
        v-model:memory-mb="form.config.memory_mb"
        v-model:timeout-seconds="form.config.timeout_seconds"
        v-model:image-pull-policy="form.config.image_pull_policy"
        :service-type="form.service_type"
        :max-timeout="maxTimeout"
      />

      <GpuConfig
        v-model="form.config.gpu"
        v-model:timeout-seconds="form.config.timeout_seconds"
        :service-type="form.service_type"
        :region-id="form.knative_region_id"
        :runtime="form.runtime"
        :runtime-version="form.runtime_version"
        :max-timeout="maxTimeout"
        :scale-to-zero="form.config.scale_to_zero"
        :min-scale="form.config.min_scale"
      />

      <ScaleConfig
        v-if="form.config.trigger_type !== 'schedule'"
        data-tour="create-scale"
        v-model:scale-to-zero="form.config.scale_to_zero"
        v-model:min-scale="form.config.min_scale"
        v-model:max-scale="form.config.max_scale"
      />

      <EnvVarsEditor v-model="form.config.env_vars" />

      <SecretEnvVarsEditor v-if="form.secrets" v-model="form.secrets" :plain-names="plainEnvNames" />
    </div>

    <p v-if="error" class="text-sm text-red-400 mt-3">{{ error }}</p>

    <div class="flex justify-between pt-4 mt-2 border-t border-gray-800/60">
      <button class="btn-secondary" @click="$emit('back')">{{ t('common.back') }}</button>
      <button class="btn-primary" :disabled="loading || !canNext" @click="$emit('submit')">
        {{ loading ? t('create.step4.submitting') : t('create.step4.submit') }}
      </button>
    </div>
  </div>
</template>

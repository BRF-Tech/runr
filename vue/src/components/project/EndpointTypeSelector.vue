<script setup>
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps({
  endpointType: { type: String, default: 'subdomain' },
  gatewayPath: { type: String, default: '' },
  gatewayMethods: { type: Array, default: () => [] },
  projectSlug: { type: String, default: '' },
  regionName: { type: String, default: '' },
  username: { type: String, default: '' },
})

defineEmits(['update:endpointType', 'update:gatewayPath', 'update:gatewayMethods'])

const options = [
  { id: 'subdomain', labelKey: 'create.endpointTypes.subdomain', activeStyle: 'background:rgb(16 185 129/0.15);border-color:rgb(16 185 129/0.5);color:#6ee7b7' },
  { id: 'gateway',   labelKey: 'create.endpointTypes.gateway',   activeStyle: 'background:rgb(139 92 246/0.15);border-color:rgb(139 92 246/0.5);color:#c4b5fd' },
]
</script>

<template>
  <section>
    <p class="section-title">{{ t('create.sections.endpointType') }}</p>
    <div class="flex gap-2">
      <button
        v-for="opt in options" :key="opt.id"
        type="button"
        class="flex-1 py-1.5 rounded-md border text-sm transition-colors"
        :class="endpointType === opt.id ? 'border' : 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'"
        :style="endpointType === opt.id ? opt.activeStyle : ''"
        @click="$emit('update:endpointType', opt.id)"
      >{{ t(opt.labelKey) }}</button>
    </div>

    <!-- Preview URL -->
    <div class="mt-2 text-xs text-gray-400 font-mono truncate">
      <template v-if="endpointType === 'subdomain'">
        {{ projectSlug || '...' }}.{{ regionName || '...' }}.domain.tld
      </template>
      <template v-else>
        {{ username || '...' }}.{{ regionName || '...' }}.domain.tld{{ gatewayPath || '/...' }}
      </template>
    </div>

    <!-- Gateway path input -->
    <div v-if="endpointType === 'gateway'" class="mt-3">
      <label class="label">{{ t('create.fields.gatewayPath') }} <span class="normal-case text-red-500/70">*</span></label>
      <input
        :value="gatewayPath"
        class="input font-mono"
        placeholder="/login"
        @input="$emit('update:gatewayPath', $event.target.value)"
      />
      <p v-if="gatewayPath && !gatewayPath.startsWith('/')" class="text-xs text-red-400 mt-1">
        {{ t('create.fields.gatewayPathSlash') }}
      </p>
    </div>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>

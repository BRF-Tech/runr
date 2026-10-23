<script setup>
// A region's Secrets tab (docs/secret-stores.md): External Secrets Operator and the region's own
// OpenBao. OpenBao needs the operator ready, so the operator card's status goes to the OpenBao
// card as it changes — an install of the operator on this tab unlocks OpenBao without a reload.
import { ref, watch } from 'vue'
import ExternalSecretsCard from './ExternalSecretsCard.vue'
import OpenBaoCard from './OpenBaoCard.vue'

const props = defineProps({
  region: { type: Object, default: null },
})

const esoStatus = ref(null)
// Another region: its operator's status comes with its own card's first read
watch(() => props.region?.id, () => { esoStatus.value = null })
</script>

<template>
  <div class="space-y-4" data-testid="region-secrets-tab">
    <ExternalSecretsCard :region="region" @status="esoStatus = $event" />
    <OpenBaoCard :region="region" :eso-status="esoStatus" />
  </div>
</template>

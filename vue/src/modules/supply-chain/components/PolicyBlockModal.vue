<script setup>
// A deploy the supply-chain policy (or data residency) refused: the reason, the violated rules
// and — with the override permission — a deploy with a recorded reason.
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldAlert, Clock } from 'lucide-vue-next'
import SeverityBadges from './SeverityBadges.vue'

const props = defineProps({
  // The 422 body: { message, code, violations, can_override, report, ... }
  block:     { type: Object, required: true },
  deploying: { type: Boolean, default: false },
})

const emit = defineEmits(['override', 'retry', 'close'])

const { t } = useI18n()
const reason = ref('')

const pending     = computed(() => props.block.code === 'supply_chain_scan_pending')
const canOverride = computed(() => !!props.block.can_override && !pending.value)
const violations  = computed(() => props.block.violations ?? [])
const validReason = computed(() => reason.value.trim().length >= 3)

function override() {
  if (!validReason.value) return
  emit('override', reason.value.trim())
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-policy-block>
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative card w-full max-w-lg space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-semibold flex items-center gap-2" :class="pending ? 'text-sky-300' : 'text-red-300'">
          <Clock v-if="pending" class="w-4 h-4" />
          <ShieldAlert v-else class="w-4 h-4" />
          {{ pending ? t('supplyChain.block.pendingTitle') : t('supplyChain.block.title') }}
        </h2>
        <button class="text-gray-400 hover:text-gray-100" @click="emit('close')">✕</button>
      </div>

      <p class="text-sm text-gray-300" data-block-message>{{ block.message }}</p>

      <div v-if="block.report?.counts" class="text-xs">
        <SeverityBadges :counts="block.report.counts" all />
      </div>

      <div v-if="violations.length" class="text-xs">
        <p class="text-gray-400 mb-1">{{ t('supplyChain.block.violations') }}</p>
        <ul class="list-disc ml-5 text-gray-300 space-y-0.5">
          <li v-for="v in violations" :key="v.rule" :data-violation="v.rule">{{ v.message }}</li>
        </ul>
      </div>

      <div v-if="canOverride" class="space-y-1.5">
        <label class="label">{{ t('supplyChain.block.reason') }}</label>
        <textarea v-model="reason" class="input h-20 text-xs resize-y" maxlength="500" data-override-reason />
        <p class="text-[11px] text-gray-500">{{ t('supplyChain.block.reasonHint') }}</p>
      </div>
      <p v-else-if="!pending && block.code !== 'region_not_allowed'" class="text-[11px] text-gray-500">{{ t('supplyChain.block.noPermission') }}</p>

      <div class="flex gap-3 pt-3 border-t border-gray-800/60">
        <button class="btn-secondary flex-1" @click="emit('close')">{{ t('supplyChain.block.cancel') }}</button>
        <button v-if="pending" class="btn-primary flex-1" :disabled="deploying" data-retry @click="emit('retry')">{{ t('supplyChain.block.retry') }}</button>
        <button v-else-if="canOverride" class="btn-primary flex-1" :disabled="deploying || !validReason" data-override @click="override">
          {{ t('supplyChain.block.override') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  replies:     { type: Array,  required: true },
  isAdmin:     { type: Boolean, default: false },
  adminReadAt: { type: String,  default: null },
  userReadAt:  { type: String,  default: null },
})

const { t } = useI18n()

const show = computed(() => {
  const pub = props.replies.filter(r => !r.is_internal)
  if (!pub.length) return false
  const last = pub[pub.length - 1]
  if (props.isAdmin) {
    if (!last.is_staff) return false
    return !!(props.userReadAt && props.userReadAt > last.created_at)
  } else {
    if (last.is_staff) return false
    return !!(props.adminReadAt && props.adminReadAt > last.created_at)
  }
})
</script>

<template>
  <span v-if="show" class="text-xs text-gray-500">&#10003; {{ t('account.ticketSeen') }}</span>
</template>

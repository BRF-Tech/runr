<script setup>
import { useConfirm } from '@/composables/useConfirm'
import ConfirmModal from './ConfirmModal.vue'

const { current, accept, cancel } = useConfirm()
</script>

<template>
  <Teleport to="body">
    <ConfirmModal
      v-if="current"
      :title="current.title"
      :message="current.message"
      :destructive="current.destructive"
      :confirm-label="current.confirmLabel"
      @confirm="accept"
      @cancel="cancel"
    />
  </Teleport>
</template>

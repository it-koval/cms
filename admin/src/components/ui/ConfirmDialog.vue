<script setup>
import Modal from './Modal.vue'
import { confirmState, settle } from './useConfirm.js'
</script>

<template>
  <Modal top :open="confirmState.open" :title="confirmState.title" :width="440" initial-focus="[data-confirm]" @update:open="(v) => !v && settle(false)">
    <p v-if="confirmState.message" class="muted" style="max-width:52ch">{{ confirmState.message }}</p>
    <template #footer>
      <button class="btn" type="button" @click="settle(false)">{{ confirmState.cancelLabel }}</button>
      <button :class="['btn', confirmState.danger ? 'danger' : 'primary']" type="button" data-confirm @click="settle(true)">{{ confirmState.confirmLabel }}</button>
    </template>
  </Modal>
</template>

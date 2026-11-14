<script setup>
import Icon from './Icon.vue'
import { toastState, toast } from './useToast.js'

function undo(t) {
  toast.dismiss(t.id)
  if (t.undo) t.undo()
}
</script>

<template>
  <div class="toasts" aria-live="polite">
    <div v-for="t in toastState.items" :key="t.id" :class="['toast', { 'err-t': t.err }]" role="status">
      <Icon :name="t.err ? 'alert' : 'check'" />
      <span>{{ t.message }}</span>
      <button v-if="t.undo" type="button" @click="undo(t)">{{ t.undoLabel }}</button>
    </div>
  </div>
</template>

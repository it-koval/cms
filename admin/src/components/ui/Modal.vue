<script setup>
// Centered dialog.  <Modal v-model:open="o" title="Название" :width="520"> ... <template #footer>buttons</template> </Modal>
import { ref, toRef } from 'vue'
import Icon from './Icon.vue'
import { useOverlay } from './focus.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  width: { type: Number, default: 520 },
  closeOnScrim: { type: Boolean, default: true },
  initialFocus: { type: String, default: '' },
  // above other dialogs: for a confirmation that opens from inside a dialog
  top: { type: Boolean, default: false },
  // extra class on the dialog («modal-full»: the whole screen on a phone)
  cls: { type: String, default: '' },
})
const emit = defineEmits(['update:open', 'close'])
const el = ref(null)
const close = () => { emit('update:open', false); emit('close') }
useOverlay(el, toRef(props, 'open'), { onEsc: close, initial: props.initialFocus, top: props.top })
</script>

<template>
  <Teleport to="body">
    <template v-if="open">
      <div :class="['scrim', { top }]" @click="closeOnScrim && close()" />
      <div ref="el" :class="['modal', { top }, cls]" role="dialog" aria-modal="true" :aria-label="title" :style="{ width: width + 'px' }">
        <header>
          <h2>{{ title }}</h2>
          <button class="btn ghost icon" type="button" :aria-label="t('ui.close')" @click="close"><Icon name="x" /></button>
        </header>
        <div class="body"><slot /></div>
        <footer v-if="$slots.footer"><slot name="footer" /></footer>
      </div>
    </template>
  </Teleport>
</template>

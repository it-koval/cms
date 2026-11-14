<script setup>
// Right-hand drawer.  <Drawer v-model:open="o" title="Заявка № 12"> ... <template #footer>buttons</template> </Drawer>
import { ref, toRef } from 'vue'
import Icon from './Icon.vue'
import { useOverlay } from './focus.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ open: { type: Boolean, default: false }, title: { type: String, default: '' }, width: { type: Number, default: 440 } })
const emit = defineEmits(['update:open', 'close'])
const el = ref(null)
const close = () => { emit('update:open', false); emit('close') }
useOverlay(el, toRef(props, 'open'), { onEsc: close })
</script>

<template>
  <Teleport to="body">
    <template v-if="open">
      <div class="scrim" @click="close" />
      <aside ref="el" class="drawer" role="dialog" aria-modal="true" :aria-label="title" :style="{ width: `min(${width}px, 100vw)` }">
        <header>
          <h2>{{ title }}</h2>
          <button class="btn ghost icon" type="button" :aria-label="t('ui.close')" @click="close"><Icon name="x" /></button>
        </header>
        <div class="body"><slot /></div>
        <footer v-if="$slots.footer"><slot name="footer" /></footer>
      </aside>
    </template>
  </Teleport>
</template>

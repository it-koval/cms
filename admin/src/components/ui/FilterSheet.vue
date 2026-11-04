<script setup>
// The filters of a list. On a desktop and a tablet the fields stay where they are (this component draws nothing around them);
// on a phone they sit behind one button «Фильтры» with the number of the active ones, and open as a sheet from the bottom edge.
//   <FilterSheet :count="activeFilters" @reset="resetFilters"> <select>…</select> <select>…</select> </FilterSheet>
// `count` = how many filters are on (0 = none); `reset` is offered in the sheet only while count > 0.
import { ref } from 'vue'
import Icon from './Icon.vue'
import Modal from './Modal.vue'
import { vp } from '@/utils/viewport.js'
import { t } from '@/i18n/index.js'

defineProps({
  count: { type: Number, default: 0 },
  title: { type: String, default: () => t('ui.filters') },
})
defineEmits(['reset'])
const open = ref(false)
</script>

<template>
  <slot v-if="!vp.phone" />
  <template v-else>
    <button type="button" :class="['btn fs-btn', { 'is-on': count > 0 }]" aria-haspopup="dialog" @click="open = true">
      <Icon name="filter" size="s16" />{{ title }}<span v-if="count" class="cnt" :aria-label="t('ui.filters_on', { count })">{{ count }}</span>
    </button>
    <Modal v-model:open="open" :title="title" cls="filter-sheet">
      <div class="fs-body"><slot /></div>
      <template #footer>
        <button v-if="count" type="button" class="btn" @click="$emit('reset')">{{ t('ui.reset') }}</button>
        <button type="button" class="btn primary" @click="open = false">{{ t('ui.done') }}</button>
      </template>
    </Modal>
  </template>
</template>

<style>
.fs-btn.is-on{border-color:var(--cy);color:var(--cy-dd);background:var(--cy-50)}
.fs-btn .cnt{margin-left:2px}
.fs-body{display:flex;flex-direction:column;gap:14px}
.fs-body select,.fs-body input:not([type=checkbox]):not([type=radio]){width:100%;min-width:0}
.fs-body .rq-dates{display:flex;align-items:center;gap:8px}
.fs-body .rq-dates input{flex:1}
</style>

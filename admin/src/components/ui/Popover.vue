<script setup>
// Anchored popover / menu.
//   <button ref="btn" @click="open = !open" :aria-expanded="open">...</button>
//   <Popover v-model:open="open" :anchor="btn" align="right" :width="310"> <button class="mi">Пункт</button> </Popover>
// Closes on Esc and on a click outside (on a phone the menu is a sheet from the bottom edge and a real dimmed layer, .pop-scrim, lies behind it); focus returns to the anchor. Menu rows: <button class="mi">, <button class="mi danger">, <hr>.
// On open the focus goes to the first button, or to the row marked data-autofocus (the selected one of a list; it is scrolled into view). `label` = aria-label of the menu.
import { nextTick, onBeforeUnmount, ref, toRef, watch } from 'vue'
import { useOverlay } from './focus.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  anchor: { type: Object, default: null }, // an element (template ref value)
  align: { type: String, default: 'right' }, // right edge of popover = right edge of anchor, or 'left'
  width: { type: Number, default: 0 },
  role: { type: String, default: 'menu' },
  label: { type: String, default: '' },
  cls: { type: String, default: '' },
})
const emit = defineEmits(['update:open'])
const el = ref(null)
const pos = ref({ left: 0, top: 0 })

function place() {
  if (!props.anchor || !el.value) return
  const r = props.anchor.getBoundingClientRect()
  const pw = el.value.offsetWidth
  const ph = el.value.offsetHeight
  let left = props.align === 'left' ? r.left : r.right - pw
  left = Math.max(8, Math.min(left, innerWidth - pw - 8))
  let top = r.bottom + 6
  if (top + ph > innerHeight - 8) top = Math.max(8, r.top - ph - 6)
  pos.value = { left, top }
}
const close = () => {
  emit('update:open', false)
  if (props.anchor && props.anchor.focus) props.anchor.focus({ preventScroll: true })
}
useOverlay(el, toRef(props, 'open'), { onEsc: close, initial: '[data-autofocus]', top: true, lock: false, restore: false })
const onDown = (e) => {
  if (!props.open || !el.value) return
  if (e.target.classList && e.target.classList.contains('pop-scrim')) return // the dimming of a phone sheet closes it on its own click, so the tap never reaches the page
  if (el.value.contains(e.target) || (props.anchor && props.anchor.contains(e.target))) return
  emit('update:open', false)
}
const onKey = (e) => {
  if (!props.open || !el.value) return
  if (!el.value.contains(document.activeElement)) return
  if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && props.role === 'menu') {
    const items = [...el.value.querySelectorAll('button.mi:not([disabled])')]
    if (!items.length) return
    const i = items.indexOf(document.activeElement)
    items[e.key === 'ArrowDown' ? (i + 1) % items.length : (i - 1 + items.length) % items.length].focus()
    e.preventDefault()
  }
}
watch(() => props.open, async (v) => {
  if (v) {
    await nextTick()
    place()
    document.addEventListener('pointerdown', onDown, true)
    document.addEventListener('keydown', onKey, true)
    window.addEventListener('resize', place)
    const mark = el.value && el.value.querySelector('[data-autofocus]')
    const first = mark || (el.value && el.value.querySelector('button:not([disabled]),input,select,textarea'))
    if (first) first.focus({ preventScroll: true })
    if (mark) mark.scrollIntoView({ block: 'nearest' })
  } else {
    document.removeEventListener('pointerdown', onDown, true)
    document.removeEventListener('keydown', onKey, true)
    window.removeEventListener('resize', place)
  }
}, { immediate: true })
onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', onDown, true)
  document.removeEventListener('keydown', onKey, true)
  window.removeEventListener('resize', place)
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="pop-scrim" @click="close" />
    <div v-if="open" ref="el" :class="['pop', cls]" :role="role" :aria-label="label || undefined" :style="{ left: pos.left + 'px', top: pos.top + 'px', width: width ? width + 'px' : undefined }" @click="role === 'menu' && $emit('update:open', false)">
      <slot />
    </div>
  </Teleport>
</template>

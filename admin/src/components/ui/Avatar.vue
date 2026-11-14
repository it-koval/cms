<script setup>
// Round avatar with the initials of a person: <Avatar name="Анна Сергеева" :size="32" muted />  (muted = a blocked or not yet active person)
import { computed } from 'vue'
import { initialsOf } from '@/utils/format.js'

const props = defineProps({ name: { type: String, default: '' }, size: { type: Number, default: 32 }, muted: { type: Boolean, default: false } })
const style = computed(() => ({ width: props.size + 'px', height: props.size + 'px', fontSize: Math.round(props.size * 0.38) + 'px' }))
</script>

<template>
  <span :class="['avatar', { muted }]" :style="style" aria-hidden="true">{{ initialsOf(name) }}</span>
</template>

<style scoped>
.avatar{display:inline-grid;place-items:center;flex:none;border-radius:50%;background:var(--cy-100);color:var(--cy-dd);font-weight:650;letter-spacing:.02em;line-height:1}
.avatar.muted{background:var(--hair-2);color:var(--ink-3)}
</style>

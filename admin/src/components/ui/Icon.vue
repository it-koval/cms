<script setup>
// <Icon name="plus" size="s14" />  — sizes: '' (18px), 's14', 's16', 's20'. Color = currentColor. An unknown name falls back to the document icon.
import { computed } from 'vue'
import { ICONS } from './icons.js'
const props = defineProps({ name: { type: String, required: true }, size: { type: String, default: '' } })
const d = computed(() => ICONS[props.name] || ICONS.doc) // a name the admin does not know (a site's own icon word) still draws something
</script>

<template>
  <svg :class="['ic', size]" viewBox="0 0 24 24" aria-hidden="true"><path :d="d" /></svg>
</template>

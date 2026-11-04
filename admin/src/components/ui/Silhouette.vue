<script setup>
// Abstract thumbnail of a section type: <Silhouette type="hero" :w="184" :h="92" fit="meet" /> (type = section type key)
import { computed } from 'vue'
import { silhouette } from '@/preview/silhouettes.js'
import { getSectionType } from '@/schema/sections.js'

const props = defineProps({
  type: { type: String, default: '' }, kind: { type: String, default: '' }, tone: { type: String, default: 'white' },
  w: { type: Number, default: 184 }, h: { type: Number, default: 96 },
  fit: { type: String, default: 'slice' }, // 'slice' fills the box (outline thumbnails), 'meet' shows the whole drawing (block library)
})
const svg = computed(() => {
  const t = props.type ? getSectionType(props.type) : null
  const svg = silhouette(t ? t.sil : props.kind || 'text', t ? t.tone : props.tone, props.w, props.h)
  return props.fit === 'meet' ? svg.replace('xMidYMid slice', 'xMidYMid meet') : svg
})
</script>

<template>
  <span class="sil" style="display:block;width:100%;height:100%" v-html="svg" />
</template>

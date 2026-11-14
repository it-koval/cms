<script setup>
// Chip with the work status of a request: Новая / В работе / Закрыта / Спам.
import { computed } from 'vue'
import { workLabel } from '@/composables/requestMeta.js'

const props = defineProps({ status: { type: String, required: true } })
const label = computed(() => workLabel(props.status))
</script>

<template>
  <span :class="['chip', 'wc-' + status, { new: status === 'new' }]">{{ label }}</span>
</template>

<style scoped>
.wc-progress{background:var(--cy-50);color:var(--cy-dd)}
.wc-closed{background:var(--hair-2);color:var(--ink-2)}
.wc-spam{background:var(--hair-2);color:var(--ink-3);text-decoration:line-through;text-decoration-thickness:1px}
</style>

<script setup>
// Loading placeholder: <Skeleton :rows="6" />
import { t } from '@/i18n/index.js'
defineProps({ rows: { type: Number, default: 5 } })
const widths = [40, 100, 82, 94, 60, 88]
</script>

<template>
  <div class="sk-wrap" aria-busy="true" :aria-label="t('ui.loading')">
    <div v-for="i in rows" :key="i" class="sk" :style="{ width: widths[(i - 1) % 6] + '%', height: (i - 1) % 3 === 0 ? '18px' : '14px' }" />
  </div>
</template>

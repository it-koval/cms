<script setup>
// The numbers above a list: [{ label, kind, value }] from the server (Metric::count / Metric::sum of the resource). Quiet tiles, one line; nothing is drawn without metrics.
import { nf } from '@/utils/format.js'
import { t } from '@/i18n/index.js'

defineProps({ metrics: { type: Array, default: () => [] } })
</script>

<template>
  <dl v-if="metrics.length" class="lm" :aria-label="t('collections.lm_label')">
    <div v-for="(m, i) in metrics" :key="i" class="lm-i">
      <dt>{{ m.label }}</dt>
      <dd class="num">{{ nf(m.value) }}</dd>
    </div>
  </dl>
</template>

<style scoped>
.lm { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 14px; }
.lm-i { min-width: 120px; padding: 9px 14px 10px; background: var(--sheet); border: 1px solid var(--hair); border-radius: var(--r-lg); box-shadow: var(--sh-1); }
.lm dt { font-size: var(--t-xs); font-weight: 580; color: var(--ink-2); line-height: 1.3; }
.lm dd { margin: 2px 0 0; font-size: var(--t-lg); font-weight: 650; letter-spacing: -.01em; line-height: 1.2; }
@media (max-width: 767px) {
  .lm { flex-wrap: nowrap; overflow-x: auto; margin: 0 -16px 14px; padding: 2px 16px 4px; scroll-snap-type: x proximity; scrollbar-width: none; }
  .lm-i { flex: none; min-width: 130px; scroll-snap-align: start; }
}
</style>

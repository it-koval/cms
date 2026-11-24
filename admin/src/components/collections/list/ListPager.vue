<script setup>
// Pages under a list: «1–50 из 120», the page numbers (the first, the last and the neighbours of the current one), «Назад / Вперёд» and the page size.
// v-model:page, v-model:per-page. Nothing is drawn when everything fits on one page and the size cannot be reduced below the total.
import { computed } from 'vue'
import { t } from '@/i18n/index.js'
import Icon from '@/components/ui/Icon.vue'
import { nf } from '@/utils/format.js'
import { vp } from '@/utils/viewport.js'

const props = defineProps({
  page: { type: Number, required: true },
  perPage: { type: Number, required: true },
  total: { type: Number, required: true },
  sizes: { type: Array, default: () => [25, 50, 100, 200] },
  cursors: { type: Object, default: null },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['update:page', 'update:perPage'])

const pages = computed(() => Math.max(props.cursors ? props.page + Number(!!props.cursors.nextCursor) : 1, Math.ceil(props.total / props.perPage)))
const previous = computed(() => props.page > 1 && (!props.cursors || !!props.cursors.previousCursor))
const next = computed(() => props.cursors ? !!props.cursors.nextCursor : props.page < pages.value)
const from = computed(() => Math.min(props.total, (props.page - 1) * props.perPage + 1))
const to = computed(() => Math.min(props.total, props.page * props.perPage))
const allSizes = computed(() => [...new Set([...props.sizes, props.perPage])].sort((a, b) => a - b))
// 1 … 4 5 [6] 7 8 … 20
const numbers = computed(() => {
  const out = []
  const last = pages.value
  const near = [...new Set([1, last, props.page - 1, props.page, props.page + 1])].filter((n) => n >= 1 && n <= last).sort((a, b) => a - b)
  let prev = 0
  for (const n of near) {
    if (n - prev > 1) out.push('…')
    out.push(n)
    prev = n
  }
  return out
})
const go = (n) => { if (!props.busy && n >= 1 && n <= pages.value && n !== props.page) emit('update:page', n) }
</script>

<template>
  <div v-if="total > Math.min(...allSizes) || previous || next" class="lp">
    <span class="lp-n num" role="status" aria-live="polite">{{ t('collections.lp_range', { from: nf(from), to: nf(to), total: nf(total) }) }}</span>
    <nav v-if="pages > 1" class="lp-nav" :aria-label="t('collections.lp_nav')">
      <button type="button" class="btn ghost icon sm" :aria-label="t('collections.lp_prev')" :disabled="busy || !previous" @click="go(page - 1)"><Icon name="chevL" size="s16" /></button>
      <span v-if="vp.phone" class="lp-cur num">{{ t('collections.lp_page_of', { page, pages }) }}</span>
      <template v-for="(n, i) in (vp.phone ? [] : numbers)" :key="i">
        <span v-if="n === '…'" class="lp-gap" aria-hidden="true">…</span>
        <button v-else type="button" :class="['lp-b', { on: n === page }]" :disabled="busy || (n === page + 1 && !next) || (n === page - 1 && !previous)" :aria-current="n === page ? 'page' : undefined" :aria-label="t('collections.lp_page', { page: n })" @click="go(n)">{{ n }}</button>
      </template>
      <button type="button" class="btn ghost icon sm" :aria-label="t('collections.lp_next')" :disabled="busy || !next" @click="go(page + 1)"><Icon name="chevR" size="s16" /></button>
    </nav>
    <label class="lp-s">
      <span>{{ t('collections.lp_per_page') }}</span>
      <select :value="perPage" :disabled="busy" @change="emit('update:perPage', Number($event.target.value))">
        <option v-for="s in allSizes" :key="s" :value="s">{{ s }}</option>
      </select>
    </label>
  </div>
</template>

<style scoped>
.lp { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border-top: 1px solid var(--hair); background: var(--sheet-2); border-radius: 0 0 var(--r-lg) var(--r-lg); }
.lp-n { color: var(--ink-2); font-size: var(--t-sm); flex: 1 1 100%; }
.lp-nav { display: flex; align-items: center; gap: 2px; flex: 1 1 100%; justify-content: space-between; }
.lp-cur { font-size: var(--t-sm); color: var(--ink-2); }
.lp-b { min-width: 28px; height: 28px; padding: 0 6px; border: 1px solid transparent; border-radius: var(--r); background: none; font-size: var(--t-sm); font-weight: 560; color: var(--ink-2); font-variant-numeric: tabular-nums; }
.lp-b:hover { background: var(--cy-50); color: var(--brand-text); }
.lp-b.on { background: var(--cy); border-color: var(--cy); color: var(--brand-ink); }
.lp-gap { padding: 0 4px; color: var(--ink-3); }
.lp-s { display: inline-flex; align-items: center; gap: 8px; font-size: var(--t-sm); color: var(--ink-2); flex: 1 1 100%; justify-content: space-between; }
.lp-s select { width: auto; height: 44px; min-width: 96px; padding-right: 26px; font-size: 16px; }
@media (min-width: 768px) {
  .lp { gap: 14px; padding: 10px 14px; }
  .lp-n { flex: 0 1 auto; margin-right: auto; }
  .lp-nav, .lp-s { flex: 0 1 auto; justify-content: flex-start; }
  .lp-s select { height: 28px; min-width: 70px; font-size: var(--t-sm); }
}
</style>

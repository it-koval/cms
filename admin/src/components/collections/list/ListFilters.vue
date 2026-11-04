<script setup>
// The filters bar of a list. It draws whatever the schema says a field can be filtered by (field.filter = { kind, options }) — nothing about a particular collection:
//   text         a search box for one field (LIKE)                     select      one of the values                      multi       any of several values (a box with ticks)
//   toggle       «Любой / Да / Нет»                                    dateRange   «с … по …» (two dates)                  numberRange «от … до …»
//   relation     any of the records of another collection (its titles are loaded when the box opens)
// `filters` = [{ k, label, kind, options, field }] (listModel.filtersOf), `facets` = { key: { value: count } } (how many records have each value, whole collection) shown beside the options.
// v-model:filter is the object that goes to the server: { status:'published', date:{ from, to }, tags:['a','b'] }; a cleared control removes its key.
import { ref } from 'vue'
import { relationLoaded, relationOptions } from '@/components/form/relationOptions.js'
import Icon from '@/components/ui/Icon.vue'
import Popover from '@/components/ui/Popover.vue'
import { isSet } from './listModel.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  filters: { type: Array, required: true },
  filter: { type: Object, required: true },
  facets: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:filter'])

const set = (k, v) => {
  const next = { ...props.filter }
  if (isSet(v)) next[k] = v
  else delete next[k]
  emit('update:filter', next)
}
const setEdge = (k, edge, v) => {
  const cur = { from: '', to: '', ...(props.filter[k] || {}) }
  cur[edge] = v
  set(k, cur)
}
const optionsOf = (f) => {
  if (f.options && f.options.length) return f.options
  return Object.keys(props.facets[f.k] || {}).map((v) => [v, v]) // a text field made a select: its values are what the records have
}
const countOf = (f, value) => {
  const n = props.facets[f.k] && props.facets[f.k][value]
  return n == null ? '' : ` (${n})`
}
const multiText = (f) => {
  const v = props.filter[f.k] || []
  if (!v.length) return t('collections.lf_any_multi')
  if (v.length === 1) return multiOptions(f).find((o) => String(o[0]) === String(v[0]))?.[1] || v[0]
  return t('collections.lf_selected', { count: v.length })
}
const toggleMulti = (f, value, on) => {
  const cur = props.filter[f.k] || []
  set(f.k, on ? [...cur, value] : cur.filter((x) => x !== value))
}

/* relation: the options are the records of the other collection */
const relationOf = (f) => relationOptions(f.field && f.field.to)
const multiOptions = (f) => (f.kind === 'relation' ? relationOf(f) : optionsOf(f))

/* the box of «multi» / «relation» */
const pop = ref({ open: false, f: null, anchor: null })
function openPop(f, ev) {
  const same = pop.value.open && pop.value.f && pop.value.f.k === f.k
  pop.value = { open: !same, f, anchor: ev.currentTarget }
}
const closePop = (v) => { pop.value = { ...pop.value, open: v } }
const toggleValue = (k) => (props.filter[k] === '1' ? '1' : props.filter[k] === '0' ? '0' : '')
</script>

<template>
  <div class="lf" role="group" :aria-label="t('collections.lf_group')">
    <div v-for="f in filters" :key="f.k" :class="['lf-i', 'k-' + f.kind]">
      <span :id="'lf-l-' + f.k" class="lf-l">{{ f.label }}</span>

      <input
        v-if="f.kind === 'text'" type="search" :aria-labelledby="'lf-l-' + f.k" :value="filter[f.k] || ''" :placeholder="t('collections.lf_contains')" autocomplete="off"
        @input="set(f.k, $event.target.value)"
      >

      <select v-else-if="f.kind === 'select'" :aria-labelledby="'lf-l-' + f.k" :value="filter[f.k] || ''" @change="set(f.k, $event.target.value)">
        <option value="">{{ t('collections.lf_any') }}</option>
        <option v-for="o in optionsOf(f)" :key="o[0]" :value="o[0]">{{ o[1] }}{{ countOf(f, o[0]) }}</option>
      </select>

      <select v-else-if="f.kind === 'toggle'" :aria-labelledby="'lf-l-' + f.k" :value="toggleValue(f.k)" @change="set(f.k, $event.target.value)">
        <option value="">{{ t('collections.lf_any') }}</option>
        <option value="1">{{ t('collections.lf_yes') }}</option>
        <option value="0">{{ t('collections.lf_no') }}</option>
      </select>

      <span v-else-if="f.kind === 'dateRange'" class="lf-r" role="group" :aria-labelledby="'lf-l-' + f.k">
        <input type="date" :aria-label="t('collections.lf_from_date')" :value="(filter[f.k] || {}).from || ''" @input="setEdge(f.k, 'from', $event.target.value)">
        <i aria-hidden="true">—</i>
        <input type="date" :aria-label="t('collections.lf_to_date')" :value="(filter[f.k] || {}).to || ''" @input="setEdge(f.k, 'to', $event.target.value)">
      </span>

      <span v-else-if="f.kind === 'numberRange'" class="lf-r" role="group" :aria-labelledby="'lf-l-' + f.k">
        <input type="number" :placeholder="t('collections.lf_from_ph')" :aria-label="t('collections.lf_from')" :value="(filter[f.k] || {}).from ?? ''" @input="setEdge(f.k, 'from', $event.target.value)">
        <i aria-hidden="true">—</i>
        <input type="number" :placeholder="t('collections.lf_to_ph')" :aria-label="t('collections.lf_to')" :value="(filter[f.k] || {}).to ?? ''" @input="setEdge(f.k, 'to', $event.target.value)">
      </span>

      <button
        v-else type="button" :class="['lf-m', { on: (filter[f.k] || []).length }]" :aria-labelledby="'lf-l-' + f.k" aria-haspopup="dialog" :aria-expanded="pop.open && pop.f && pop.f.k === f.k"
        @click="openPop(f, $event)"
      ><span>{{ multiText(f) }}</span><Icon name="chevD" size="s14" /></button>
    </div>

    <Popover :open="pop.open" :anchor="pop.anchor" align="left" :width="260" role="dialog" :label="pop.f ? pop.f.label : ''" @update:open="closePop">
      <div v-if="pop.f" class="lf-pop">
        <p v-if="!multiOptions(pop.f).length" class="muted lf-none">{{ pop.f.kind === 'relation' && !relationLoaded(pop.f.field && pop.f.field.to) ? t('collections.lf_loading') : t('collections.lf_nothing') }}</p>
        <label v-for="o in multiOptions(pop.f)" :key="o[0]" class="lf-ck">
          <input type="checkbox" :checked="(filter[pop.f.k] || []).includes(String(o[0]))" @change="toggleMulti(pop.f, String(o[0]), $event.target.checked)">
          <span>{{ o[1] }}</span><small v-if="pop.f.kind === 'multi'">{{ countOf(pop.f, o[0]).replace(/[() ]/g, '') }}</small>
        </label>
        <button v-if="(filter[pop.f.k] || []).length" type="button" class="btn ghost sm lf-clear" @click="set(pop.f.k, [])">{{ t('collections.lf_clear') }}</button>
      </div>
    </Popover>
  </div>
</template>

<style scoped>
.lf { display: flex; flex-wrap: wrap; gap: 10px 12px; align-items: flex-end; }
.lf-i { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.lf-l { font-size: var(--t-xs); font-weight: 580; color: var(--ink-2); line-height: 1.2; }
.lf-i input, .lf-i select, .lf-m { height: 32px; font-size: var(--t-sm); }
.lf-i.k-text input { width: 180px; }
.lf-i.k-select select, .lf-i.k-toggle select { width: auto; min-width: 150px; max-width: 220px; }
.lf-r { display: inline-flex; align-items: center; gap: 6px; }
.lf-r i { font-style: normal; color: var(--ink-3); }
.lf-r input[type=date] { width: 138px; padding: 0 8px; }
.lf-r input[type=number] { width: 84px; }
.lf-m { display: inline-flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 150px; max-width: 220px; padding: 0 8px 0 10px; border: 1px solid var(--ctl); border-radius: var(--r); background: #fff; color: var(--ink-2); text-align: left; }
.lf-m span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lf-m:hover { border-color: var(--ink-3); }
.lf-m.on { color: var(--ink); border-color: var(--cy); background: var(--cy-50); font-weight: 560; }
.lf-m[aria-expanded="true"] { border-color: var(--cy); box-shadow: 0 0 0 3px var(--cy-100); }
.lf-pop { display: flex; flex-direction: column; max-height: 300px; overflow-y: auto; padding: 2px; }
.lf-ck { display: flex; align-items: center; gap: 9px; min-height: 32px; padding: 0 8px; border-radius: 5px; cursor: pointer; font-size: var(--t-sm); }
.lf-ck:hover { background: var(--cy-50); }
.lf-ck input { width: 16px; height: 16px; accent-color: var(--cy); flex: none; }
.lf-ck span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lf-ck small { color: var(--ink-3); font-variant-numeric: tabular-nums; }
.lf-none { padding: 8px; font-size: var(--t-sm); }
.lf-clear { align-self: flex-start; margin: 4px 0 2px 4px; }
@media (max-width: 767px) {
  .lf { flex-direction: column; align-items: stretch; flex-wrap: nowrap; gap: 14px; }
  .lf-i input, .lf-i select, .lf-m { height: 44px; font-size: 16px; }
  .lf-i.k-text input, .lf-i.k-select select, .lf-i.k-toggle select { width: 100%; max-width: none; }
  .lf-m { width: 100%; max-width: none; }
  .lf-r { display: flex; }
  .lf-r input[type=date], .lf-r input[type=number] { width: auto; flex: 1; min-width: 0; }
  .lf-l { font-size: var(--t-sm); }
  .lf-ck { min-height: 48px; }
  .lf-ck input { width: 20px; height: 20px; }
  .lf-pop { max-height: 55vh; }
}
</style>

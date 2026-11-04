<script setup>
// A board: columns of cards. A card goes to another column by dragging it, or with its own buttons «← / →» (Alt + ← / → on the card does the same). It draws and listens;
// the parent saves the change and gives the new items back (it may show the move at once and take it back when the save fails).
//
//   <KanbanBoard :columns="[{ value: 'new', label: 'Новая' }]" :items="rows" :column-of="(r) => r.work" :label="titleOf" :disabled="busy" @move="onMove">
//     <template #card="{ item }">…the card's content…</template>
//   </KanbanBoard>
//
// columns    [{ value, label, tone? }] in the order they stand; an item whose value is in none of them goes to the last column «Без значения» (only when there are such items)
// column-of  (item) => value of the column the item is in
// label      (item) => the name of a card for screen readers
// can-move   (item) => boolean | 'reason'  — a card that stays where it is (no right, no version in this language)
// A PHONE shows one column at a time (the tabs above the cards) and a card moves with «Переместить» (a list of columns), not with drag or the arrows.
// EVENTS     move({ item, from, to }) — `from` and `to` are column values; the order inside a column is not kept by the board
import { computed, reactive, ref } from 'vue'
import Icon from './Icon.vue'
import Popover from './Popover.vue'
import { vp } from '@/utils/viewport.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  columns: { type: Array, required: true },
  items: { type: Array, default: () => [] },
  itemKey: { type: String, default: 'id' },
  columnOf: { type: Function, required: true },
  label: { type: Function, default: (i) => String(i.id) },
  canMove: { type: Function, default: null },
  disabled: { type: Boolean, default: false },
  emptyText: { type: String, default: () => t('ui.kanban_empty') },
  ariaLabel: { type: String, default: () => t('ui.kanban_board') },
})
const emit = defineEmits(['move', 'blocked'])

const NONE = '\u0000none'
const known = computed(() => new Set(props.columns.map((c) => String(c.value))))
const strayed = computed(() => props.items.filter((i) => !known.value.has(String(props.columnOf(i) ?? ''))))
const cols = computed(() => [
  ...props.columns.map((c) => ({ ...c, value: String(c.value) })),
  ...(strayed.value.length ? [{ value: NONE, label: t('ui.kanban_none'), tone: 'none' }] : []),
])
const cards = (col) => props.items.filter((i) => (col.value === NONE ? !known.value.has(String(props.columnOf(i) ?? '')) : String(props.columnOf(i) ?? '') === col.value))
const idOf = (i) => String(i[props.itemKey])
const lockOf = (i) => (props.canMove ? props.canMove(i) : true)
const live = ref('')

/* ---------- a phone: one column at a time, «Переместить» ---------- */
const tab = ref(null)
const curTab = computed(() => {
  if (tab.value != null && cols.value.some((c) => c.value === tab.value)) return tab.value
  const first = cols.value.find((c) => cards(c).length) || cols.value[0]
  return first ? first.value : ''
})
const showCol = (col) => !vp.phone || col.value === curTab.value
const mv = reactive({ open: false, item: null, anchor: null })
function openMove(item, ev) { mv.item = item; mv.anchor = ev.currentTarget; mv.open = true }
const targets = computed(() => (mv.item ? props.columns.filter((c) => String(c.value) !== String(props.columnOf(mv.item) ?? '')) : []))
function moveTo(c) { if (mv.item) put(mv.item, String(c.value)); mv.open = false }

/* ---------- moving ---------- */
function put(item, to) {
  if (props.disabled) return
  const verdict = lockOf(item)
  if (verdict !== true) {
    const why = typeof verdict === 'string' ? verdict : t('ui.kanban_locked')
    live.value = why
    emit('blocked', why)
    return
  }
  const from = String(props.columnOf(item) ?? '')
  if (to === from || to === NONE) return
  const col = props.columns.find((c) => String(c.value) === to)
  live.value = t('ui.kanban_moved', { card: props.label(item), column: col ? col.label : to })
  emit('move', { item, from, to })
}
/** The column a key or a button sends the card to (d = -1 left, +1 right); a card in "Без значения" goes to the first column with →. */
const aimOf = (item, d) => props.columns[props.columns.findIndex((c) => String(c.value) === String(props.columnOf(item) ?? '')) + d]
const hasSide = (item, d) => !!aimOf(item, d)
function go(item, d) {
  const next = aimOf(item, d)
  if (next) put(item, String(next.value))
}
function onCardKey(e, item) {
  if (!e.altKey || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return
  e.preventDefault()
  go(item, e.key === 'ArrowRight' ? 1 : -1)
}

/* ---------- dragging ---------- */
const drag = reactive({ id: '', over: '' })
function onDragStart(e, item) {
  if (props.disabled || lockOf(item) !== true) { e.preventDefault(); return }
  drag.id = idOf(item)
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', drag.id)
}
function onColOver(e, col) {
  if (!drag.id || col.value === NONE) return
  e.preventDefault()
  e.dataTransfer.dropEffect = 'move'
  drag.over = col.value
}
function onDrop(e, col) {
  if (!drag.id) return
  e.preventDefault()
  const item = props.items.find((i) => idOf(i) === drag.id)
  drag.id = ''
  drag.over = ''
  if (item && col.value !== NONE) put(item, col.value)
}
const onEnd = () => { drag.id = ''; drag.over = '' }
</script>

<template>
  <div class="kb" role="group" :aria-label="ariaLabel" @dragend="onEnd">
    <div v-if="vp.phone" class="kb-tabs" role="tablist" :aria-label="ariaLabel">
      <button v-for="col in cols" :key="col.value" type="button" role="tab" :aria-selected="curTab === col.value" :class="['kb-tab', { on: curTab === col.value }]" @click="tab = col.value">
        {{ col.label }}<span class="kb-n num">{{ cards(col).length }}</span>
      </button>
    </div>
    <section
      v-for="col in cols" v-show="showCol(col)" :key="col.value" :class="['kb-col', 'tone-' + (col.tone || col.value), { over: drag.over === col.value }]" :aria-label="`${col.label}: ${cards(col).length}`"
      @dragover="onColOver($event, col)" @dragleave="drag.over === col.value && !$event.currentTarget.contains($event.relatedTarget) && (drag.over = '')" @drop="onDrop($event, col)"
    >
      <header class="kb-h">
        <slot name="colhead" :column="col" :count="cards(col).length"><b>{{ col.label }}</b></slot>
        <span class="kb-n num">{{ cards(col).length }}</span>
      </header>
      <ul class="kb-l">
        <li
          v-for="item in cards(col)" :key="idOf(item)" :class="['kb-card', { dragging: drag.id === idOf(item), still: lockOf(item) !== true }]" :draggable="!disabled && lockOf(item) === true"
          @dragstart="onDragStart($event, item)" @keydown="onCardKey($event, item)"
        >
          <div class="kb-body"><slot name="card" :item="item" :column="col" /></div>
          <div v-if="!disabled && lockOf(item) === true && vp.phone" class="kb-mv kb-mv-ph">
            <button type="button" class="btn sm" :aria-label="t('ui.move_label', { name: label(item) })" aria-haspopup="menu" @click="openMove(item, $event)"><Icon name="swap" size="s16" />{{ t('ui.kanban_move') }}</button>
          </div>
          <div v-else-if="!disabled && lockOf(item) === true" class="kb-mv">
            <button type="button" class="btn ghost icon sm" :disabled="!hasSide(item, -1)" :aria-label="t('ui.kanban_back_label', { name: label(item) })" :title="t('ui.kanban_back_title')" @click="go(item, -1)"><Icon name="chevL" size="s14" /></button>
            <button type="button" class="btn ghost icon sm" :disabled="!hasSide(item, 1)" :aria-label="t('ui.kanban_next_label', { name: label(item) })" :title="t('ui.kanban_next_title')" @click="go(item, 1)"><Icon name="chevR" size="s14" /></button>
          </div>
        </li>
        <li v-if="!cards(col).length" class="kb-empty">{{ emptyText }}</li>
      </ul>
    </section>
    <Popover v-model:open="mv.open" :anchor="mv.anchor" align="right" :width="240" :label="t('ui.kanban_move_to')">
      <button v-for="c in targets" :key="c.value" type="button" class="mi" role="menuitem" @click="moveTo(c)"><Icon name="chevR" size="s16" />{{ t('ui.kanban_to_column', { column: c.label }) }}</button>
    </Popover>
    <div class="sr" role="status" aria-live="polite" aria-atomic="true">{{ live }}</div>
  </div>
</template>

<style scoped>
.kb { display: flex; gap: 12px; align-items: flex-start; padding: 14px; overflow-x: auto; }
.kb-col { flex: 1 1 0; min-width: 250px; background: var(--sheet-2); border: 1px solid var(--hair); border-radius: var(--r-lg); padding: 8px; transition: border-color var(--dur), background var(--dur); }
.kb-col.over { border-color: var(--cy); background: var(--cy-50); }
.kb-h { display: flex; align-items: center; gap: 8px; padding: 4px 6px 8px; }
.kb-h b { font-weight: 650; }
.kb-n { margin-left: auto; min-width: 24px; padding: 1px 7px; border-radius: 10px; background: #fff; border: 1px solid var(--hair); text-align: center; font-size: var(--t-xs); color: var(--ink-2); }
.kb-l { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; min-height: 48px; }
.kb-card { position: relative; background: #fff; border: 1px solid var(--hair); border-radius: var(--r); padding: 9px 10px 8px; cursor: grab; box-shadow: var(--sh-1); }
.kb-card:hover, .kb-card:focus-within { border-color: var(--ctl); box-shadow: var(--sh-2); }
.kb-card.still { cursor: default; }
.kb-card.dragging { opacity: .4; }
.kb-body { min-width: 0; }
.kb-mv { display: flex; justify-content: flex-end; gap: 2px; margin-top: 4px; opacity: 0; transition: opacity var(--dur); }
.kb-card:hover .kb-mv, .kb-card:focus-within .kb-mv { opacity: 1; }
.kb-mv .btn { color: var(--ink-2); }
.kb-empty { padding: 14px 8px; text-align: center; font-size: var(--t-sm); color: var(--ink-3); border: 1px dashed var(--hair); border-radius: var(--r); }
@media (hover: none) { .kb-mv { opacity: 1; } }
@media (max-width: 767px) { .kb { flex-direction: column; } .kb-col { width: 100%; min-width: 0; } }
.kb-tabs { align-self: stretch; display: flex; gap: 6px; overflow-x: auto; flex: none; max-width: 100%; padding-bottom: 2px; scrollbar-width: none; }
.kb-tab { flex: 1 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 0 14px; border: 1px solid var(--hair); border-radius: var(--r); background: #fff; font-weight: 580; color: var(--ink-2); white-space: nowrap; }
.kb-tab.on { background: var(--cy-50); border-color: var(--cy); color: var(--cy-dd); font-weight: 650; }
.kb-mv.kb-mv-ph { opacity: 1; justify-content: flex-start; margin-top: 8px; }
@media (max-width: 767px) {
  .kb { padding: 12px; gap: 10px; }
  .kb-card { cursor: default; }
  .kb-h { display: none; } /* the tab above names the column */
}
</style>

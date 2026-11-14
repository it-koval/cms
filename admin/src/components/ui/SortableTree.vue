<script setup>
// A tree whose nodes people move: by dragging the handle, or from the keyboard. ONE component for the page tree, the menu items, the records of a collection with tree(), the folders of the media library.
// It draws and listens; it never changes what it was given: every move is validated (treeOps.js) and handed to the parent as a `move` event, the parent saves it and gives the new tree back.
//
//   <SortableTree :nodes="tree" :max-depth="2" :can-drop="rule" :locked="(n) => n.fixed" @move="save" @invalid="toast.error">
//     <template #default="{ node, level, hasKids, open }">…the row's content…</template>
//     <template #actions="{ node }">…buttons on the right…</template>
//   </SortableTree>
//
// nodes      nested: [{ id, ...whatever, children: [...] }]  (id-key / kids-key rename the two keys); treeOps.nest(flatRows) makes one from [{ id, parentId, ... }]
// max-depth  levels allowed, counted from 1 (a menu with one nested level = 2; no limit by default); a node with children counts with all its branch
// can-drop   ({ node, parent, index, nodes }) => true | false | 'reason in plain words' — the rule «куда можно», asked after the built in rules (not into itself or its own branch, depth)
// locked     (node) => boolean | 'reason' — a node that is not moved at all (the home page): no handle, nothing can be dropped on its place, it can still be a parent
// tools      the buttons «Выше / Ниже / Вложить / Вынести» on every row (the keyboard way); off when the rows have their own (the menu editor)
// disabled   nothing moves (while a save is on its way)
// EVENTS     move({ id, node, parentId, index, from:{parentId,index}, to:{parentId,index}, nodes, groups }) — `nodes` is the new tree, `groups` the parents whose children changed
//            (treeOps.payload(nodes, groups) gives the server's list); invalid(reason) — a move that is not allowed, in plain words
// DRAG       the top third of a row = before it, the bottom third = after it (an open branch: the first child), the middle = inside it (as the last child). A line or a frame shows where it lands;
//            a place that is not allowed shows the reason under the tree and takes no drop.
// A PHONE has no hover and no drag with a mouse: every row gets one button «Переместить», a menu of the same four steps (Выше / Ниже / Вложить / Вынести).
// KEYBOARD   on the handle: Alt + ↑ ↓ (up, down), Alt + → (into the brother above), Alt + ← (out next to the parent). The focus stays on what was pressed, a polite live line says where the node is now.
import { computed, nextTick, reactive, ref, watch } from 'vue'
import Icon from './Icon.vue'
import Popover from './Popover.vue'
import { vp } from '@/utils/viewport.js'
import * as T from './treeOps.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  nodes: { type: Array, default: () => [] },
  idKey: { type: String, default: 'id' },
  kidsKey: { type: String, default: 'children' },
  maxDepth: { type: Number, default: Infinity },
  canDrop: { type: Function, default: null },
  locked: { type: Function, default: null },
  label: { type: Function, default: (n) => n.label || n.title || String(n.id) },
  rowClass: { type: Function, default: null },
  tools: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: () => t('ui.tree_label') },
  startClosed: { type: Boolean, default: false }, // branches start folded
  foldable: { type: Boolean, default: true }, // a twist to fold a branch (off: every branch is always open)
  bare: { type: Boolean, default: false }, // the rows draw no frame of their own (the content has its own)
})
const emit = defineEmits(['move', 'invalid'])

const opts = computed(() => ({ id: props.idKey, kids: props.kidsKey, maxDepth: props.maxDepth, canDrop: props.canDrop }))
const idOf = (n) => n[props.idKey]
const lockOf = (n) => (props.locked ? props.locked(n) : false)

/* ---------- open / folded ---------- */
const folded = ref(new Set()) // ids of folded branches (all open unless startClosed)
const opened = ref(new Set()) // with startClosed: ids of opened ones
const isOpen = (id) => (!props.foldable ? true : props.startClosed ? opened.value.has(id) : !folded.value.has(id))
function toggle(id) {
  const set = new Set(props.startClosed ? opened.value : folded.value)
  if (set.has(id)) set.delete(id)
  else set.add(id)
  if (props.startClosed) opened.value = set
  else folded.value = set
}
const list = computed(() => T.rows(props.nodes, isOpen, opts.value))
const branchIds = computed(() => {
  const out = []
  const walk = (nodes) => nodes.forEach((n) => { const k = n[props.kidsKey] || []; if (k.length) { out.push(idOf(n)); walk(k) } })
  walk(props.nodes)
  return out
})
function expandAll() { if (props.startClosed) opened.value = new Set(branchIds.value); else folded.value = new Set() }
function collapseAll() { if (props.startClosed) opened.value = new Set(); else folded.value = new Set(branchIds.value) }
function reveal(id) { // open every branch above a node (after it was moved inside a folded one)
  const set = new Set(props.startClosed ? opened.value : folded.value)
  let at = T.find(props.nodes, id, opts.value)
  while (at && at.parent) {
    if (props.startClosed) set.add(idOf(at.parent))
    else set.delete(idOf(at.parent))
    at = T.find(props.nodes, idOf(at.parent), opts.value)
  }
  if (props.startClosed) opened.value = set
  else folded.value = set
}

/* ---------- what each row's buttons may do ---------- */
const DIRS = ['up', 'down', 'in', 'out']
const stepOk = computed(() => {
  if (!props.tools || props.disabled) return {}
  const out = {}
  list.value.forEach((r) => {
    const id = idOf(r.node)
    out[id] = lockOf(r.node) ? {} : Object.fromEntries(DIRS.map((d) => [d, T.canStep(props.nodes, id, d, opts.value).ok]))
  })
  return out
})
const TOOLS = [
  { dir: 'up', icon: 'up', text: t('ui.tree_up'), title: t('ui.tree_up_title') },
  { dir: 'down', icon: 'down', text: t('ui.tree_down'), title: t('ui.tree_down_title') },
  { dir: 'in', icon: 'chevR', text: t('ui.tree_in'), title: t('ui.tree_in_title') },
  { dir: 'out', icon: 'chevL', text: t('ui.tree_out'), title: t('ui.tree_out_title') },
]

/* ---------- the row menu of a phone ---------- */
const mn = reactive({ open: false, row: null, anchor: null })
function openMenu(row, e) { mn.row = row; mn.anchor = e.currentTarget; mn.open = !(mn.open && mn.row === row) }
function menuStep(dir) {
  const row = mn.row
  mn.open = false
  if (row) doStep(row, dir)
}
const menuOk = (dir) => !!(mn.row && (stepOk.value[idOf(mn.row.node)] || {})[dir])

/* ---------- announcing and focus ---------- */
const live = ref('')
let focusAfter = null // { id, dir, at } — after the parent gives the new tree back, the focus returns to what was pressed (a save that failed leaves it; it expires)
watch(() => props.nodes, async () => {
  if (!focusAfter) return
  const f = focusAfter
  focusAfter = null
  if (Date.now() - f.at > 8000) return
  reveal(f.id)
  await nextTick()
  const row = root.value && root.value.querySelector(`[data-id="${CSS.escape(String(f.id))}"]`)
  if (!row) return
  const pressed = f.dir && row.querySelector(`[data-dir="${f.dir}"]:not([disabled])`)
  const target = pressed || row.querySelector('.st-grip')
  if (target) target.focus({ preventScroll: false })
})
function describe(result) {
  const at = T.find(result.nodes, result.id, opts.value)
  if (!at) return ''
  const place = { name: props.label(at.node), place: at.index + 1, total: at.siblings.length }
  return at.parent ? t('ui.tree_at_parent', { ...place, parent: props.label(at.parent) }) : t('ui.tree_at_top', place)
}

/* ---------- moving ---------- */
function commit(id, r, dir = '') {
  if (!r.ok) {
    live.value = r.reason
    emit('invalid', r.reason)
    return false
  }
  if (r.same) return false
  const at = T.find(r.nodes, id, opts.value)
  live.value = describe({ nodes: r.nodes, id })
  focusAfter = { id, dir, at: Date.now() }
  emit('move', { id, node: at ? at.node : null, parentId: r.to.parentId, index: r.to.index, from: r.from, to: r.to, nodes: r.nodes, groups: T.groupsOf(r) })
  return true
}
function doStep(row, dir) {
  if (props.disabled || lockOf(row.node)) return
  commit(idOf(row.node), T.step(props.nodes, idOf(row.node), dir, opts.value), dir)
}
const KEYS = { ArrowUp: 'up', ArrowDown: 'down', ArrowRight: 'in', ArrowLeft: 'out' }
function onGripKey(e, row) {
  const dir = KEYS[e.key]
  if (!dir || !e.altKey) return
  e.preventDefault()
  doStep(row, dir)
}

/* ---------- dragging (the handle is the only thing that drags: the rows may hold inputs) ---------- */
const root = ref(null)
const drag = reactive({ id: '', over: '', pos: '', ok: false, reason: '', to: null, result: null })
const reset = () => Object.assign(drag, { id: '', over: '', pos: '', ok: false, reason: '', to: null, result: null })

function onDragStart(e, row) {
  if (props.disabled || lockOf(row.node)) { e.preventDefault(); return }
  drag.id = String(idOf(row.node))
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', drag.id)
  const el = e.currentTarget.closest('.st-row')
  if (el && e.dataTransfer.setDragImage) e.dataTransfer.setDragImage(el, 18, 18)
}
/** Where a drop on this row at this height lands: the new parent and the final index there. */
function aim(row, pos) {
  const id = String(idOf(row.node))
  const mine = T.find(props.nodes, drag.id, opts.value)
  const fix = (parentId, raw) => (mine && String(mine.parentId ?? '') === String(parentId ?? '') && mine.index < raw ? raw - 1 : raw) // the dragged node leaves its place first
  if (pos === 'inside') return { parentId: id, index: null }
  if (pos === 'after') return row.hasKids && row.open ? { parentId: id, index: 0 } : { parentId: row.parentId, index: fix(row.parentId, row.index + 1) }
  return { parentId: row.parentId, index: fix(row.parentId, row.index) }
}
function onRowOver(e, row) {
  if (!drag.id) return
  const id = String(idOf(row.node))
  if (id === drag.id) { drag.over = ''; return }
  const box = e.currentTarget.getBoundingClientRect()
  const y = (e.clientY - box.top) / Math.max(1, box.height)
  const pos = y < 0.32 ? 'before' : y > 0.68 ? 'after' : 'inside'
  const to = aim(row, pos)
  const r = T.move(props.nodes, drag.id, to.parentId, to.index, opts.value)
  const locked = lockOf(row.node) && pos !== 'inside' // the place of a locked node (the home page) is not for anyone
  Object.assign(drag, { over: id, pos, ok: r.ok && !r.same && !locked, reason: locked ? t('ui.tree_home_first') : r.same ? '' : r.reason, to, result: r })
  if (drag.ok) {
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
  } else {
    e.dataTransfer.dropEffect = 'none'
  }
}
function onDrop(e) {
  if (!drag.id) return
  const { ok, reason, result, id } = { ok: drag.ok, reason: drag.reason, result: drag.result, id: drag.id }
  e.preventDefault()
  const original = list.value.find((r) => String(idOf(r.node)) === id)
  reset()
  if (!original) return
  if (ok && result) commit(idOf(original.node), result)
  else if (reason) { live.value = reason; emit('invalid', reason) }
}
function onLeave(e) {
  if (!e.currentTarget.contains(e.relatedTarget)) drag.over = ''
}

defineExpose({ expandAll, collapseAll, reveal, folded, isOpen })
</script>

<template>
  <div ref="root" :class="['st-wrap', { bare }]" @dragend="reset" @dragleave="onLeave">
    <ul class="st" role="tree" :aria-label="ariaLabel">
      <li
        v-for="row in list" :key="idOf(row.node)" role="treeitem" :aria-level="row.level" :aria-posinset="row.index + 1" :aria-setsize="row.count"
        :aria-expanded="row.hasKids ? row.open : undefined" :data-id="idOf(row.node)" class="st-item" :style="{ '--lv': row.level - 1 }"
      >
        <div
          :class="['st-row', rowClass && rowClass(row.node), {
            dragging: drag.id === String(idOf(row.node)),
            over: drag.over === String(idOf(row.node)),
            'd-before': drag.over === String(idOf(row.node)) && drag.pos === 'before', 'd-after': drag.over === String(idOf(row.node)) && drag.pos === 'after',
            'd-inside': drag.over === String(idOf(row.node)) && drag.pos === 'inside', 'd-bad': drag.over === String(idOf(row.node)) && !drag.ok,
          }]"
          @dragover="onRowOver($event, row)" @drop="onDrop"
        >
          <button
            v-if="!lockOf(row.node) && !vp.phone" type="button" class="st-grip" :draggable="!disabled" :disabled="disabled" :title="t('ui.tree_grip_title')"
            :aria-label="t('ui.tree_grip_label', { name: label(row.node) })" @dragstart="onDragStart($event, row)" @keydown="onGripKey($event, row)"
          ><Icon name="grip" size="s16" /></button>
          <span v-else-if="lockOf(row.node)" class="st-grip st-fixed" :title="typeof lockOf(row.node) === 'string' ? lockOf(row.node) : t('ui.tree_fixed')"><Icon name="lock" size="s14" /></span>
          <button
            v-if="foldable && row.hasKids" type="button" :class="['st-twist', { open: row.open }]" :aria-label="t(row.open ? 'ui.tree_fold' : 'ui.tree_unfold', { name: label(row.node) })" @click="toggle(idOf(row.node))"
          ><Icon name="chevR" size="s14" /></button>
          <span v-else-if="foldable" class="st-sp" />
          <div class="st-body">
            <slot :node="row.node" :level="row.level" :has-kids="row.hasKids" :open="row.open">{{ label(row.node) }}</slot>
          </div>
          <button
            v-if="tools && !lockOf(row.node) && vp.phone" type="button" class="btn ghost icon sm st-mv" aria-haspopup="menu" :disabled="disabled" :aria-expanded="mn.open && mn.row === row"
            :aria-label="t('ui.move_label', { name: label(row.node) })" @click="openMenu(row, $event)"
          ><Icon name="swap" size="s16" /></button>
          <span v-else-if="tools && !lockOf(row.node)" class="st-tools">
            <button
              v-for="tool in TOOLS" :key="tool.dir" type="button" class="btn ghost icon sm" :data-dir="tool.dir" :title="tool.title" :aria-label="`${tool.text}: ${label(row.node)}`"
              :disabled="disabled || !(stepOk[idOf(row.node)] || {})[tool.dir]" @click="doStep(row, tool.dir)"
            ><Icon :name="tool.icon" size="s14" /></button>
          </span>
          <span class="st-acts"><slot name="actions" :node="row.node" :level="row.level" /></span>
        </div>
      </li>
    </ul>
    <Popover v-model:open="mn.open" :anchor="mn.anchor" align="right" :width="260" :label="t('ui.tree_move')">
      <button v-for="tool in TOOLS" :key="tool.dir" type="button" class="mi" role="menuitem" :disabled="!menuOk(tool.dir)" @click="menuStep(tool.dir)"><Icon :name="tool.icon" size="s16" />{{ tool.text }}</button>
    </Popover>
    <p v-if="drag.id && drag.over && !drag.ok && drag.reason" class="st-why" role="status"><Icon name="info" size="s14" />{{ drag.reason }}</p>
    <div class="sr" role="status" aria-live="polite" aria-atomic="true">{{ live }}</div>
  </div>
</template>

<style scoped>
.st-wrap { position: relative; }
.st { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.st-item { padding-left: calc(var(--lv) * 30px); }
.st-row { position: relative; display: flex; align-items: center; gap: 4px; min-height: 42px; padding: 3px 8px 3px 2px; border: 1px solid var(--hair); border-radius: var(--r); background: #fff; }
.st-row:hover, .st-row:focus-within { border-color: var(--ctl); }
.st-body { flex: 1; min-width: 0; }
.st-grip { flex: none; width: 28px; height: 32px; display: grid; place-items: center; border: 0; background: none; color: var(--ink-3); border-radius: 4px; cursor: grab; padding: 0; }
.st-grip:hover:not(:disabled), .st-grip:focus-visible { color: var(--brand-text); background: var(--cy-50); }
.st-grip:active { cursor: grabbing; }
.st-grip:disabled { cursor: default; opacity: .5; }
.st-fixed { cursor: default; }
.st-twist { flex: none; width: 24px; height: 28px; display: grid; place-items: center; border: 0; background: none; color: var(--ink-2); border-radius: 4px; padding: 0; transition: transform var(--dur); }
.st-twist.open { transform: rotate(90deg); }
.st-twist:hover { background: var(--hair-2); color: var(--brand-text); }
.st-sp { flex: none; width: 24px; }
.st-tools { flex: none; display: inline-flex; opacity: 0; transition: opacity var(--dur); }
.st-row:hover .st-tools, .st-row:focus-within .st-tools { opacity: 1; }
.st-tools .btn { color: var(--ink-2); }
.st-acts { flex: none; display: inline-flex; align-items: center; gap: 2px; }
.st-row.dragging { opacity: .45; }
.bare .st-row { min-height: 0; padding: 0; border: 0; background: none; border-radius: 0; }
.bare .st-row:hover, .bare .st-row:focus-within { border: 0; }
.bare .st-body { padding: 0; }
.bare .st-row.d-inside { border: 0; background: none; box-shadow: none; outline: 2px solid var(--cy); outline-offset: 0; border-radius: var(--r); }
/* the drop mark: a line above or below the row, a frame when the node goes inside */
.st-row.d-before::before, .st-row.d-after::after { content: ""; position: absolute; left: -2px; right: -2px; height: 3px; border-radius: 2px; background: var(--cy); pointer-events: none; z-index: 2; }
.st-row.d-before::before { top: -5px; }
.st-row.d-after::after { bottom: -5px; }
.st-row.d-inside { border-color: var(--cy); background: var(--cy-50); box-shadow: 0 0 0 2px var(--cy-100); }
.st-row.d-bad { border-color: var(--red); background: var(--red-50); box-shadow: none; }
.st-row.d-bad::before, .st-row.d-bad::after { background: var(--red); }
.st-why { display: flex; gap: 6px; align-items: center; margin: 8px 2px 0; font-size: var(--t-sm); color: var(--red); }
@media (hover: none) { .st-tools { opacity: 1; } }
.st-mv { color: var(--ink-2); }
@media (max-width: 767px) { .st-item { padding-left: calc(var(--lv) * 18px); } .st-row { padding-left: 6px; } .st-twist { width: 36px; height: 36px; } }
</style>

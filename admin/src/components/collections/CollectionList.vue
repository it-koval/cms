<script setup>
// List of a collection: header, search + filters, table or cards, selection with bulk actions, row actions, metrics, pages, empty states.
// Everything the list knows about a collection comes from its schema (cfg): fields (columns, sortable, filter kind), actions, metrics, views, perPage. Nothing here names a collection.
// DATA: api.collections.query(col, { q, filter, sort, page, perPage }) — the database does the search, the filters, the sort and the pages — { items, total, facets, metrics }.
// STATE: the filters, the sort, the page size and the view are kept for the person on the server (list/useListState.js, api.prefs) and come back on the next visit.
// FILTERS (list/ListFilters.vue): the status (every collection) and each field the schema marks filterable. COLUMNS: the title (with one more line) and the fields the schema shows in the list;
// a column of a sortable field sorts on a click (none → ascending → descending → none).
// ACTIONS (actions/useActions.js): Action::...->top() are buttons in the header, ->bulk() act on the ticked rows (a bar appears), ->row() are in the row menu; a modal with fields, a confirmation,
// the answer (message, reload, redirect, download) are handled there. The record page has the ->form() ones.
// VIEWS (list/listModel.js viewsOf): table, cards, calendar, kanban («Доска»), tree («Структура», every collection with tree()). The choice is kept per person with the other list state.
// Calendar, board and tree show ALL the records at once (no pages): the calendar stands them on the dates the view names (click a day = a new record on it, drag = new dates), the board cuts them by
// a select field (drag = the field changes), the tree nests them (drag = api.collections.tree). A change of a date or of the board's field is an ordinary update of the draft (hooks run),
// shown at once and taken back if the server refuses. The tree cannot be dragged while a search or a filter hides records.
// A sortable card list (cfg.sortable) also has «Изменить порядок»: a mode where cards are dragged or moved with arrows, saved by api.collections.reorder.
// The list works in the CONTENT LANGUAGE (app.locale): rows describe that language's version (a record without one has status 'missing' and the fields of the main language).
// Records are always born in the main language (add / duplicate), the content language then switches to it; the order is the same in every language.
// On a site with 2+ languages every row shows a status chip per language (table: «RU ● Опубликован  EN ◌ Нет перевода», cards: the language on screen has the word, the others a dot),
// the status filter has «Без перевода на …», a row without a version opens the record in the «Версии ещё нет» state, and «Изменить порядок» keeps working on ALL records.
// A site with one language shows none of it.
// RIGHTS (area col:<key>): without «Править» there is no «Добавить», «Изменить порядок», «Дублировать» and the switch is off; without «Удалять» the row menu has no «Удалить»;
// a version that waits for publication wears a «Ждёт публикации» chip. Actions the person may not run are not sent by the server at all.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRouter } from 'vue-router'
import { api } from '@/api/index.js'
import { PREVIEW, thumbUrl } from '@/utils/media.js'
import { app, can, refreshCounters, useLocaleGuard } from '@/stores/app.js'
import Icon from '@/components/ui/Icon.vue'
import Popover from '@/components/ui/Popover.vue'
import StatusChip from '@/components/ui/StatusChip.vue'
import LangChips from './LangChips.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Skeleton from '@/components/ui/Skeleton.vue'
import ListFilters from './list/ListFilters.vue'
import FilterSheet from '@/components/ui/FilterSheet.vue'
import { vp } from '@/utils/viewport.js'
import ListMetrics from './list/ListMetrics.vue'
import ListPager from './list/ListPager.vue'
import CellValue from './list/CellValue.vue'
import ActionDialog from './actions/ActionDialog.vue'
import { useActions } from './actions/useActions.js'
import { useListState } from './list/useListState.js'
import { activeCount, columnsOf, filtersOf, nextSort, sortDir, sortKeysOf, VIEW_LABELS, viewConfig, viewsOf, WIDE_VIEWS } from './list/listModel.js'
import CalendarView from './list/CalendarView.vue'
import KanbanBoard from '@/components/ui/KanbanBoard.vue'
import SortableTree from '@/components/ui/SortableTree.vue'
import * as T from '@/components/ui/treeOps.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { useToast } from '@/components/ui/useToast.js'
import { fmtUpdated } from '@/utils/format.js'
import { htmlToText } from '@/utils/html.js'
import { isMain, isMulti, pathPrefix, withLang } from '@/utils/locales.js'
import { freshIds, labelsOf, countText, pickFields, statusOf, titleOf, toggleLabelOf, toggleNote, dataFields } from './labels.js'
import { cap, onLang, siteIn, toLang, up, verAcc } from '@/utils/langWords.js'
import { t } from '@/i18n/index.js'

const props = defineProps({ cfg: { type: Object, required: true } })
const LOC = app.locale
const COL = props.cfg.key
const router = useRouter()
const confirm = useConfirm()
const toast = useToast()
const L = computed(() => labelsOf(props.cfg.key))
const MULTI = isMulti() // language chips, the «Без перевода» filter and the notes below appear only on a site with 2+ languages
const IS_MAIN = isMain(app.locale)
const mainLang = computed(() => app.languages.main)

const canEdit = computed(() => can('col:' + props.cfg.key, 'edit'))
const canDelete = computed(() => can('col:' + props.cfg.key, 'delete'))
const canPublish = computed(() => can('col:' + props.cfg.key, 'publish'))
const awaitingOf = (r) => Object.values(r.locales || {}).some((v) => v && v.awaiting) || !!r.awaiting

/* ---------- what the person set: search, filters, sort, page, view ---------- */
const filters = filtersOf(props.cfg, MULTI && !IS_MAIN ? cap(t('collections.cl_filter_missing', { lang: toLang(app.locale) })) : '')
const ls = useListState(props.cfg, filters)
const { q, filter, sort, page, perPage, view } = ls
const views = viewsOf(props.cfg)
const isCards = computed(() => view.value === 'cards')
const wide = computed(() => WIDE_VIEWS.includes(view.value)) // calendar, board, tree: every record at once, no pager
const columns = computed(() => columnsOf(props.cfg))
const sortKeys = sortKeysOf(props.cfg)
const titleSortable = computed(() => sortKeys.includes(props.cfg.titleField))
const sortOptions = computed(() => {
  const own = dataFields(props.cfg).filter((f) => f.list && f.list.sortable)
  if (!own.length) return []
  return [
    ...own.flatMap((f) => [{ v: f.k, t: `${f.label} ↑` }, { v: '-' + f.k, t: `${f.label} ↓` }]),
    { v: '-updatedAt', t: t('collections.cl_sort_recent') },
  ]
})
// the sort select: on cards, and on a phone for the table too (a table card has no header to sort by)
const sortChoices = computed(() => {
  if (isCards.value) return sortOptions.value
  const out = [...(titleSortable.value ? [{ v: props.cfg.titleField, t: t('collections.cl_sort_title_asc') }, { v: '-' + props.cfg.titleField, t: t('collections.cl_sort_title_desc') }] : []), ...sortOptions.value]
  if (!out.some((o) => o.v === '-updatedAt')) out.push({ v: '-updatedAt', t: t('collections.cl_sort_recent') })
  return out
})
const searchPlaceholder = computed(() => {
  const names = (props.cfg.searchKeys || []).map((k) => (dataFields(props.cfg).find((f) => f.k === k) || {}).label).filter(Boolean)
  return names.length ? t('collections.cl_search_in', { fields: names.slice(0, 3).join(', ').toLowerCase() }) : t('collections.search')
})

const rows = ref([])
const total = ref(0)
const facets = ref({})
const metrics = ref([])
const counter = computed(() => app.collections.find((c) => c.key === COL))
const unfilteredTotal = ref(0)
const wholeTotal = computed(() => counter.value?.count ?? unfilteredTotal.value)
const translatedCount = computed(() => counter.value?.locales?.[LOC]?.count ?? Object.entries(facets.value.status || {}).filter(([status]) => status !== 'missing').reduce((n, [, count]) => n + count, 0))
const loaded = ref(false)
const loadError = ref('')
const refreshing = ref(false)
const adding = ref(false)
let seq = 0
let alive = true
let debounce = null
onBeforeUnmount(() => { alive = false; clearTimeout(debounce); window.removeEventListener('keydown', onKey) })

const nFilters = computed(() => activeCount(filter.value))
const filtered = computed(() => !!q.value.trim() || nFilters.value > 0)

// every record that matches for the views that have no pages; the tree wants the order of the site
async function queryAll(base) {
  const params = { ...base, page: 1, perPage: 200, ...(view.value === 'tree' ? { sort: 'position' } : {}) }
  const first = await api.collections.query(props.cfg.key, params)
  const records = new Map(first.items.map((row) => [row.id, row]))
  const pages = Math.ceil(first.total / first.perPage)
  let total = first.total
  for (let p = 2; p <= pages; p++) {
    const more = await api.collections.query(props.cfg.key, { ...params, page: p })
    total = Math.max(total, more.total)
    if (!more.items.length) break
    for (const row of more.items) records.set(row.id, row)
  }
  return { ...first, items: [...records.values()], total, page: 1, perPage: Math.max(1, records.size) }
}
async function load() {
  if (!alive) return
  const my = ++seq
  refreshing.value = true
  try {
    const base = { ...ls.params(), locale: LOC }
    const res = wide.value ? await queryAll(base) : await api.collections.query(props.cfg.key, base)
    if (!alive || my !== seq) return
    // a page that no longer exists (records were deleted, the filter narrowed): the last one
    const last = Math.max(1, Math.ceil(res.total / res.perPage))
    if (res.page > last && res.total > 0) { page.value = last; return }
    loadError.value = ''
    rows.value = res.items
    total.value = res.total
    facets.value = res.facets || {}
    metrics.value = res.metrics || []
    if (!filtered.value) unfilteredTotal.value = res.total
    // the ticks of rows that are gone (deleted, filtered out and not on this page) are dropped
    if (selected.value.size && res.total === 0) selected.value = new Set()
    loaded.value = true
  } catch (e) {
    if (!alive || my !== seq) return
    loaded.value = true
    // a saved filter or sort the server no longer accepts (the schema changed): start clean instead of failing every visit
    if (e.status === 422 && (nFilters.value || sort.value)) {
      toast.error(t('collections.cl_filters_stale'))
      ls.state.filter = {}
      ls.state.sort = ''
      return
    }
    loadError.value = e.message || t('collections.cl_load_failed')
  } finally {
    if (alive && my === seq) refreshing.value = false
  }
}
const schedule = (ms = 220) => { clearTimeout(debounce); debounce = setTimeout(load, ms) }
// any change of what the person set goes back to the first page (the page watcher then loads); on the first page it loads itself, after a short pause for typing
watch([q, filter, sort, perPage], () => {
  if (!ls.ready.value || reorder.on) return
  selected.value = new Set()
  if (page.value !== 1) { page.value = 1; return }
  schedule()
}, { deep: true })
watch(page, () => { if (ls.ready.value && !reorder.on) schedule(0) })
// another view: the pages differ (a calendar has none), so the list is read anew
watch(view, () => {
  if (!ls.ready.value || reorder.on) return
  clearSelection()
  if (page.value !== 1) page.value = 1
  else schedule(0)
})
onMounted(async () => {
  window.addEventListener('keydown', onKey)
  await ls.restore()
  if (alive) await load()
})

const sub = computed(() => {
  const n = countText(props.cfg.key, wholeTotal.value)
  // in another language: how many of them are there ("12 записей, на английском — 6")
  return `${MULTI && !IS_MAIN ? t('collections.cl_count_lang', { total: n, lang: onLang(app.locale), translated: translatedCount.value }) : n}. ${props.cfg.description}`
})
// the note next to «Добавить»: where the new record is born (in the main language, whatever language is on screen)
const addNote = computed(() => (IS_MAIN
  ? t('collections.cl_add_note_main', { lang: onLang(mainLang.value) })
  : t('collections.cl_add_note_other', { lang: onLang(mainLang.value), version: up(verAcc(app.locale)) })))

function resetFilters() { ls.reset() }
const sortBy = (key) => { sort.value = nextSort(sort.value, key) }
const ariaSort = (key) => ({ asc: 'ascending', desc: 'descending' }[sortDir(sort.value, key)] || 'none')

/* ---------- selection and actions ---------- */
const selected = ref(new Set())
const bulkActions = computed(() => actions.at('bulk'))
const hasBulk = computed(() => bulkActions.value.length > 0)
const topActions = computed(() => actions.at('top'))
const rowActions = computed(() => actions.at('row'))
const pageIds = computed(() => rows.value.map((r) => r.id))
const allOnPage = computed(() => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.has(id)))
const someOnPage = computed(() => pageIds.value.some((id) => selected.value.has(id)))
function tick(id, on) {
  const next = new Set(selected.value)
  if (on) next.add(id)
  else next.delete(id)
  selected.value = next
}
function tickPage(on) {
  const next = new Set(selected.value)
  pageIds.value.forEach((id) => (on ? next.add(id) : next.delete(id)))
  selected.value = next
}
const clearSelection = () => { selected.value = new Set() }
const actions = useActions(() => props.cfg, {
  onDone: async () => { clearSelection(); await load() },
  rowsOf: (ids) => rows.value.filter((r) => ids.includes(r.id)),
})
const runBulk = (a) => actions.start(a, [...selected.value])
const iconOf = (a) => a.icon || (a.danger ? 'trash' : '')

/* ---------- row helpers ---------- */
const subLine = (r) => {
  const f = props.cfg.subField
  if (!f) return ''
  const field = dataFields(props.cfg).find((x) => x.k === f)
  if (field && field.t === 'text' && field.inputType === 'date') return '' // the date is a column, not a caption
  const v = field && field.rich ? htmlToText(r[f]).replace(/\n+/g, ' ') : String(r[f] || '') // a description is HTML: the list shows its text
  // an address field (slug) has no language prefix of its own: its address on the site is prefix + «/» + slug (a row without a version shows the main language's)
  return f === 'slug' ? (r.translated ? pathPrefix(app.locale) : '') + (props.cfg.slugPrefix || '') + v : v
}
const thumbOf = (r) => {
  const v = props.cfg.thumbField && r[props.cfg.thumbField]
  return v && v.src ? v : null
}
const openPath = (r) => `/collections/${props.cfg.key}/${r.id}`
const open = (r) => router.push(openPath(r))
function onRow(e, r) {
  if (e.target.closest('a,button,label,input,select,textarea')) return
  open(r)
}

/* ---------- create / duplicate / delete ---------- */
// a new record starts empty: required texts stay blank (the form asks for them), the title gets a working name where the site needs one
function initialData() {
  const o = {}
  dataFields(props.cfg).forEach((f) => { if (f.req && (f.t === 'text' || f.t === 'textarea')) o[f.k] = '' })
  o[props.cfg.titleField] = L.value.newTitle
  return o
}
// `extra` = more fields of the new record (a calendar day, the parent record of a tree)
async function add(extra = {}) {
  if (adding.value) return
  adding.value = true
  try {
    const rec = await api.collections.create(props.cfg.key, { ...initialData(), ...extra })
    refreshCounters()
    if (!IS_MAIN) toast.show(t('collections.cl_created_other', { lang: onLang(mainLang.value), version: up(verAcc(app.locale)) }))
    router.push(withLang(openPath(rec) + '?new=1', app.languages.main))
  } catch (e) {
    toast.error(e.message || t('collections.cl_create_failed'))
  } finally {
    adding.value = false
  }
}
async function duplicate(r) {
  try {
    // the copy is a new record: it is born in the main language, so it is made from the main language version (translations are not copied)
    const { item } = await api.collections.get(props.cfg.key, r.id, mainLang.value)
    const data = freshIds(pickFields(props.cfg, item))
    const tf = dataFields(props.cfg).find((f) => f.k === props.cfg.titleField)
    const base = String(data[props.cfg.titleField] || '').trim()
    const copy = base ? `${base} (копия)` : '' // i18n-ignore: the title is content of the site, not a label of the admin
    data[props.cfg.titleField] = tf && tf.max ? copy.slice(0, tf.max) : copy
    const rec = await api.collections.create(props.cfg.key, data)
    refreshCounters()
    toast.show(MULTI ? t('collections.cl_copied_multi', { lang: onLang(mainLang.value) }) : t('collections.cl_copied'))
    router.push(withLang(openPath(rec) + '?new=1', app.languages.main))
  } catch (e) {
    toast.error(e.message || t('collections.cl_copy_failed'))
  }
}
async function remove(r) {
  // the whole record goes with all its translations: say which ones
  const names = app.languages.list.filter((l) => !isMain(l.code) && r.locales && r.locales[l.code]).map((l) => l.name)
  const ok = await confirm({
    title: t('collections.delete_title', { title: titleOf(props.cfg, r) }),
    message: names.length ? t('collections.delete_message_translations', { langs: names.join(', ') }) : L.value.delMsg,
    confirmLabel: t('collections.delete'), danger: true,
  })
  if (!ok) return
  try {
    await api.collections.remove(props.cfg.key, r.id)
    tick(r.id, false)
    await Promise.all([load(), refreshCounters()])
    toast.show(t('collections.deleted'))
  } catch (e) {
    toast.error(e.message || t('collections.delete_failed'))
  }
}

/* ---------- the row switch of the collection (cfg.toggleField, named by toggleLabelOf): a draft change, goes live on publish ---------- */
const toggleName = computed(() => toggleLabelOf(props.cfg))
async function toggle(r, ev) {
  const field = props.cfg.toggleField
  const val = ev.target.checked
  const patch = async (v) => { await api.collections.update(COL, r.id, { [field]: v }, LOC); await load(); refreshCounters() }
  try {
    await patch(val)
    toast.show(toggleNote(titleOf(props.cfg, r), toggleName.value, val), {
      undo: () => patch(!val).catch((e) => toast.error(e.message)), undoLabel: t('collections.undo'),
    })
  } catch (e) {
    ev.target.checked = !val
    toast.error(e.message || t('collections.cl_toggle_failed'))
  }
}

/* ---------- calendar, board, tree ---------- */
const calCfg = computed(() => viewConfig(props.cfg, 'calendar'))
const kanCfg = computed(() => viewConfig(props.cfg, 'kanban'))
const wideBusy = ref(false)
const moveRule = (r) => (!canEdit.value ? t('collections.cl_cannot_edit') : !r.translated ? t('collections.cl_translate_first', { lang: onLang(app.locale) }) : true)
const dateField = (key) => dataFields(props.cfg).find((f) => f.k === key)
const dateValue = (key, day) => (dateField(key) && dateField(key).inputType === 'datetime-local' ? `${day}T09:00` : day)

// an ordinary update of the draft; the row changes on screen at once and goes back when the server says no
async function patchRow(r, patch, done) {
  const before = rows.value
  rows.value = rows.value.map((x) => (x.id === r.id ? { ...x, ...patch } : x))
  wideBusy.value = true
  try {
    await api.collections.update(COL, r.id, patch, LOC)
    await load()
    refreshCounters()
    const live = r.status === 'published'
    const back = Object.fromEntries(Object.keys(patch).map((k) => [k, r[k]]))
    toast.show(live ? t('collections.cl_done_live', { done: done(r) }) : done(r), { undo: () => patchRow(r, back, () => t('collections.cl_reverted')).catch(() => {}), undoLabel: t('collections.undo') })
  } catch (e) {
    if (alive) { rows.value = before; toast.error(e.message || t('collections.cl_save_failed')) }
  } finally {
    wideBusy.value = false
  }
}
async function calCreate(day) {
  const c = calCfg.value
  if (!c) return
  await add({ [c.start]: dateValue(c.start, day), ...(c.end ? { [c.end]: dateValue(c.end, day) } : {}) })
}
function calMove({ row, start, end }) {
  const c = calCfg.value
  patchRow(row, { [c.start]: start, ...(c.end && end !== undefined ? { [c.end]: end } : {}) }, (r) => t('collections.cl_dates_changed', { title: titleOf(props.cfg, r) }))
}
const kanColumns = computed(() => (kanCfg.value ? kanCfg.value.columns.map(([value, label]) => ({ value, label })) : []))
const kanLabel = (value) => (kanColumns.value.find((c) => String(c.value) === String(value)) || {}).label || value
function kanMove({ item, to }) {
  patchRow(item, { [kanCfg.value.field]: to }, (r) => t('collections.cl_moved_to', { title: titleOf(props.cfg, r), column: kanLabel(to) }))
}

const treeNodes = computed(() => T.nest(rows.value))
const incomplete = computed(() => wide.value && rows.value.length < total.value)
const treeIncomplete = computed(() => {
  const ids = new Set(rows.value.map((row) => row.id))
  return incomplete.value || rows.value.some((row) => row.parentId && !ids.has(row.parentId))
})
const treeOff = computed(() => !canEdit.value || filtered.value || wideBusy.value || treeIncomplete.value || !!loadError.value)
async function treeMove(ev) {
  if (treeOff.value) return
  const before = treeNodes.value
  wideBusy.value = true
  try {
    await api.collections.tree(props.cfg.key, T.payload(ev.nodes, ev.groups))
    await load()
    const title = titleOf(props.cfg, ev.node)
    toast.show(ev.to.parentId === ev.from.parentId ? t('collections.cl_tree_position', { title, position: ev.to.index + 1 }) : ev.to.parentId ? t('collections.cl_tree_nested', { title }) : t('collections.cl_tree_top', { title }), {
      undoLabel: t('collections.undo'),
      undo: async () => { try { await api.collections.tree(props.cfg.key, T.payload(before, ev.groups)); await load() } catch (e) { toast.error(e.message) } },
    })
  } catch (e) {
    toast.error(e.message || t('collections.cl_move_failed'))
    await load()
  } finally {
    wideBusy.value = false
  }
}
const kidsCount = (n) => (n.children || []).length

/* ---------- reorder mode (cfg.sortable): the order of the list is the order on the site ---------- */
const reorder = reactive({ on: false, busy: false, saving: false, list: [], start: [], orig: [], dragId: '', overId: '', after: false, live: '' })
// the new order goes live at once (no draft), so it needs «Публиковать», not only «Править»
const orderable = computed(() => !!props.cfg.sortable && isCards.value && loaded.value && wholeTotal.value > 1)
const canReorder = computed(() => canEdit.value && canPublish.value && orderable.value)
const cards = computed(() => (reorder.on ? reorder.list : rows.value))
const dirty = computed(() => reorder.list.some((r, i) => r.id !== reorder.start[i]))
let pendingOrder = null
async function mayLeaveOrder() {
  if (pendingOrder) await pendingOrder
  if (!dirty.value) return true
  return confirm({ title: t('settings.leave_title'), message: t('collections.cl_order_leave_message'), confirmLabel: t('settings.leave_confirm'), cancelLabel: t('settings.stay'), danger: true })
}
useLocaleGuard(mayLeaveOrder)
onBeforeRouteLeave(mayLeaveOrder)
onBeforeRouteUpdate((to, from) => to.params.col === from.params.col ? true : mayLeaveOrder())
const gridEl = ref(null)
const reorderBtn = ref(null)
const reorderNote = computed(() => {
  if (!MULTI) return t('collections.cl_reorder_note', { note: L.value.orderNote })
  if (!IS_MAIN && reorder.list.some((r) => !r.translated)) return t('collections.cl_reorder_note_hidden', { note: L.value.orderNote, site: siteIn(app.locale) })
  return t('collections.cl_reorder_note_multi', { note: L.value.orderNote })
})
const idxOf = (id) => reorder.list.findIndex((r) => r.id === id)

async function startReorder() {
  if (reorder.on || reorder.busy || !canReorder.value) return
  reorder.busy = true
  try {
    const all = await api.collections.list(props.cfg.key, { locale: LOC }) // the whole list, whatever the search and the filter were
    if (!alive) return
    if (all.length < 2) { load(); return }
    menu.value = { ...menu.value, open: false }
    clearTimeout(debounce)
    clearSelection()
    // the filters stay as the person set them (they are kept on the server and come back after the mode): the mode just shows all the records
    Object.assign(reorder, { on: true, list: all, start: all.map((r) => r.id), orig: all, dragId: '', overId: '', after: false, live: t('collections.cl_reorder_live', { count: all.length }) })
    await nextTick()
    focusMove(all[0].id, 'later')
  } catch (e) {
    if (alive) toast.error(e.message || t('collections.cl_reorder_open_failed'))
  } finally {
    reorder.busy = false
  }
}
// leave the mode and show `list` at once (the new order after saving, the old one after «Отмена»); a quiet reload follows
function leaveReorder(list) {
  rows.value = filtered.value ? rows.value : list.slice(0, perPage.value)
  Object.assign(reorder, { on: false, list: [], start: [], orig: [], dragId: '', overId: '', after: false, live: '' })
  load()
  nextTick(() => { if (reorderBtn.value) reorderBtn.value.focus() })
}
const cancelReorder = () => { if (reorder.on && !reorder.saving) leaveReorder(reorder.orig) }
function onKey(e) {
  if (e.key === 'Escape' && reorder.on && !reorder.saving && !reorder.dragId && !e.defaultPrevented) cancelReorder()
}
function saveReorder() {
  if (!reorder.on || !dirty.value || reorder.saving) return pendingOrder
  pendingOrder = commitOrder().finally(() => { pendingOrder = null })
  return pendingOrder
}
async function commitOrder() {
  const prev = [...reorder.start]
  reorder.saving = true
  try {
    await api.collections.reorder(props.cfg.key, reorder.list.map((r) => r.id))
    if (!alive) return
    leaveReorder(reorder.list)
    toast.show(t('collections.cl_order_saved'), { undo: () => restoreOrder(prev), undoLabel: t('collections.undo') })
  } catch (e) {
    if (alive) toast.error(e.message || t('collections.cl_order_save_failed'))
  } finally {
    reorder.saving = false
  }
}
async function restoreOrder(ids) {
  try {
    await api.collections.reorder(props.cfg.key, ids)
    if (reorder.on) {
      // the undo came while a new reorder mode is open: drop that mode (its starting order is stale now) and show the restored list
      const all = await api.collections.list(props.cfg.key, { locale: LOC })
      if (!alive) return
      leaveReorder(all)
    } else {
      await load()
    }
    if (alive) toast.show(t('collections.cl_order_restored'))
  } catch (e) {
    toast.error(e.message || t('collections.cl_order_restore_failed'))
  }
}

// moving: `to` is the final index of the card
function place(from, to) {
  if (reorder.saving || from === to || from < 0 || to < 0 || to >= reorder.list.length) return false
  const list = [...reorder.list]
  const [item] = list.splice(from, 1)
  list.splice(to, 0, item)
  reorder.list = list
  reorder.live = t('collections.cl_moved_to_position', { title: titleOf(props.cfg, item), position: to + 1, total: list.length })
  return true
}
// keyboard focus stays on the button that was pressed; a card that reached an end has that button disabled, so the other arrow gets it
function focusMove(id, act) {
  const slot = gridEl.value && gridEl.value.querySelector(`[data-id="${CSS.escape(id)}"]`)
  if (!slot) return
  let btn = slot.querySelector(`[data-act="${act}"]`)
  if (btn && btn.disabled) btn = slot.querySelector(`[data-act="${act === 'earlier' ? 'later' : 'earlier'}"]`)
  if (btn) btn.focus()
}
function step(r, d) {
  const i = idxOf(r.id)
  if (place(i, i + d)) nextTick(() => focusMove(r.id, d < 0 ? 'earlier' : 'later'))
}

// dragging (same events as the section outline in the page editor); the drop mark sits on the side of the card the pointer is nearer to
function onDragStart(e, r) {
  if (!reorder.on || reorder.saving) { e.preventDefault(); return }
  reorder.dragId = r.id
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', r.id)
}
function onSlotOver(e, r) {
  if (!reorder.dragId) return
  if (r.id === reorder.dragId) { reorder.overId = ''; return }
  const b = e.currentTarget.getBoundingClientRect()
  const after = e.clientX > b.left + b.width / 2
  // the final index, worked out as in onGridDrop: no mark where the drop would change nothing (next to the dragged card)
  const from = idxOf(reorder.dragId)
  let to = idxOf(r.id) + (after ? 1 : 0)
  if (from < to) to--
  reorder.overId = to === from ? '' : r.id
  reorder.after = after
}
function onGridOver(e) {
  if (!reorder.dragId) return
  e.preventDefault() // allows the drop, also in the gaps between cards
  e.dataTransfer.dropEffect = 'move'
}
function onGridLeave(e) {
  if (!e.currentTarget.contains(e.relatedTarget)) reorder.overId = ''
}
function onGridDrop(e) {
  if (!reorder.dragId) return
  e.preventDefault()
  const from = idxOf(reorder.dragId)
  const over = idxOf(reorder.overId)
  if (over >= 0) {
    let to = over + (reorder.after ? 1 : 0)
    if (from < to) to--
    place(from, to)
  }
  onDragEnd()
}
function onDragEnd() { reorder.dragId = ''; reorder.overId = '' }


/* ---------- row menu ---------- */
const menu = ref({ open: false, row: null, anchor: null })
function openMenu(r, ev) {
  const same = menu.value.open && menu.value.row && menu.value.row.id === r.id
  menu.value = { open: !same, row: r, anchor: ev.currentTarget }
}
const closeMenu = (v) => { menu.value = { ...menu.value, open: v } }
const act = (fn) => { const r = menu.value.row; menu.value.open = false; if (r) fn(r) }
const runRow = (a) => { const r = menu.value.row; menu.value.open = false; if (r) actions.start(a, [r.id]) }
</script>

<template>
  <div class="page">
    <div class="page-h">
      <div>
        <h1>{{ cfg.title }}</h1>
        <p class="sub">{{ loaded ? sub : cfg.description }}</p>
      </div>
      <div v-if="!reorder.on" class="actions">
        <button
          v-for="a in topActions" :key="a.key" type="button" :class="['btn', { danger: a.danger, loading: actions.busy.value === a.key }]" @click="actions.start(a)"
        ><Icon v-if="iconOf(a)" :name="iconOf(a)" size="s16" />{{ a.label }}</button>
        <router-link v-if="canEdit" class="btn" :to="{ path: '/trash', query: { kind: 'item', key: cfg.key } }"><Icon name="trash" size="s16" />{{ t('collections.cl_trash') }}</router-link>
        <button v-if="canReorder" ref="reorderBtn" type="button" :class="['btn', { loading: reorder.busy }]" @click="startReorder"><Icon name="grip" size="s16" />{{ t('collections.cl_reorder') }}</button>
        <div v-if="canEdit" class="cl-add">
          <button type="button" :class="['btn primary', { loading: adding }]" @click="add()"><Icon name="plus" size="s16" />{{ cfg.newLabel }}</button>
          <span v-if="MULTI" class="cl-addnote">{{ addNote }}</span>
        </div>
      </div>
    </div>

    <ListMetrics v-if="!reorder.on" :metrics="metrics" />

    <!-- reorder mode: sticky, so «Сохранить порядок» is at hand however far the list is scrolled -->
    <div v-if="reorder.on" class="cl-rbar" role="region" :aria-label="t('collections.cl_reorder_region')">
      <Icon name="grip" size="s20" />
      <p>{{ reorderNote }}</p>
      <button type="button" class="btn" :disabled="reorder.saving" @click="cancelReorder">{{ t('collections.cl_cancel') }}</button>
      <button type="button" :class="['btn primary', { loading: reorder.saving }]" :disabled="!dirty" @click="saveReorder">{{ t('collections.cl_reorder_save') }}</button>
    </div>
    <div class="sr" role="status" aria-live="polite" aria-atomic="true">{{ reorder.live }}</div>

    <!-- ticked rows: the bulk actions of the schema -->
    <div v-if="selected.size && !reorder.on" class="cl-sel" role="region" :aria-label="t('collections.cl_selected_region')">
      <b class="num">{{ t('collections.cl_selected', { count: selected.size }) }}</b>
      <button
        v-for="a in bulkActions" :key="a.key" type="button" :class="['btn sm', { danger: a.danger, loading: actions.busy.value === a.key }]" @click="runBulk(a)"
      ><Icon v-if="iconOf(a)" :name="iconOf(a)" size="s14" />{{ a.label }}</button>
      <button type="button" class="btn ghost sm cl-sel-x" @click="clearSelection">{{ t('collections.cl_clear_selection') }}</button>
    </div>

    <div class="sheet cl-sheet">
      <div v-if="!loaded" class="cl-pad"><Skeleton :rows="6" /></div>

      <div v-else-if="loadError" class="cl-pad alert err-a" role="alert"><Icon name="alert" size="s16" /><span>{{ loadError }}</span><button type="button" class="btn" :disabled="refreshing" @click="load">{{ t('settings.retry') }}</button></div>

      <!-- nothing in the collection yet -->
      <EmptyState v-else-if="!total && !filtered" :icon="cfg.icon" :title="cfg.empty.title" :text="canEdit ? cfg.empty.text : t('collections.cl_no_records')">
        <button v-if="canEdit" type="button" :class="['btn primary', { loading: adding }]" @click="add()"><Icon name="plus" size="s16" />{{ cfg.newLabel }}</button>
        <p v-if="MULTI && canEdit" class="cl-addnote c">{{ addNote }}</p>
      </EmptyState>

      <template v-else>
        <div v-if="!reorder.on" class="toolbar cl-bar">
          <div class="cl-qi">
            <label class="lf-l" for="cl-q">{{ t('collections.search') }}</label>
            <div class="search">
              <Icon name="search" size="s16" />
              <input id="cl-q" v-model="q" type="search" :placeholder="searchPlaceholder" autocomplete="off">
            </div>
          </div>
          <FilterSheet :count="nFilters" @reset="resetFilters">
            <ListFilters v-model:filter="filter" :filters="filters" :facets="facets" />
            <div v-if="(isCards || vp.phone) && sortChoices.length" class="cl-qi">
              <label class="lf-l" for="cl-sort">{{ t('collections.cl_sort_label') }}</label>
              <select id="cl-sort" v-model="sort" class="cl-sortsel">
                <option value="">{{ cfg.sortable ? t('collections.cl_sort_site') : t('collections.cl_sort_default') }}</option>
                <option v-for="o in sortChoices" :key="o.v" :value="o.v">{{ o.t }}</option>
              </select>
            </div>
          </FilterSheet>
          <button v-if="filtered" type="button" class="btn ghost sm cl-reset" @click="resetFilters"><Icon name="x" size="s14" />{{ t('collections.cl_reset') }}</button>
          <div v-if="views.length > 1" class="seg cl-views" role="group" :aria-label="t('collections.cl_views_label')">
            <button v-for="v in views" :key="v" type="button" :aria-pressed="view === v" @click="view = v">{{ VIEW_LABELS[v] || v }}</button>
          </div>
          <span v-if="filtered" class="grow num">{{ t('collections.cl_found', { total, whole: wholeTotal }) }}</span>
          <span v-else-if="orderable && L.orderHint" class="grow">{{ L.orderHint }}</span>
        </div>

        <div v-if="incomplete || (view === 'tree' && treeIncomplete)" class="alert warn-a" role="status"><Icon name="alert" size="s16" /><span>{{ t(view === 'tree' ? 'collections.cl_tree_incomplete' : 'collections.cl_incomplete', { loaded: rows.length, total }) }}</span><button type="button" class="btn sm" :disabled="refreshing" @click="load">{{ t('ui.retry') }}</button></div>

        <!-- the key to the short chips of the cards (the table spells the words out) -->
        <div v-if="MULTI && isCards && !reorder.on && rows.length" class="cl-key">
          <span class="cl-k pub"><i />{{ t('collections.cl_key_published') }}</span><span class="cl-k chg"><i />{{ t('collections.cl_key_changed') }}</span><span class="cl-k off"><i />{{ t('collections.cl_key_missing') }}</span>
        </div>

        <EmptyState v-if="!reorder.on && !rows.length && !incomplete" icon="search" :title="t('collections.cl_nothing_title')" :text="t('collections.cl_nothing_text')">
          <button type="button" class="btn" @click="resetFilters">{{ t('collections.cl_reset_filters') }}</button>
        </EmptyState>

        <!-- calendar -->
        <CalendarView
          v-else-if="view === 'calendar' && calCfg" :rows="rows" :start="calCfg.start" :end="calCfg.end" :title-of="(r) => titleOf(cfg, r)" :href-of="openPath" :editable="canEdit" :busy="wideBusy"
          :can-move="moveRule" :class="{ 'cl-busy': refreshing }" @create="calCreate" @move="calMove" @blocked="toast.error"
        />

        <!-- board -->
        <KanbanBoard
          v-else-if="view === 'kanban' && kanCfg" :columns="kanColumns" :items="rows" :column-of="(r) => r[kanCfg.field]" :label="(r) => titleOf(cfg, r)" :can-move="moveRule" :disabled="wideBusy"
          :aria-label="t('collections.cl_board_label', { title: cfg.title })" :class="{ 'cl-busy': refreshing }" @move="kanMove" @blocked="toast.error"
        >
          <template #card="{ item }">
            <router-link class="cl-link kb-t" :to="openPath(item)">{{ titleOf(cfg, item) }}</router-link>
            <div v-if="subLine(item)" class="url cl-sub">{{ subLine(item) }}</div>
            <div class="kb-s"><LangChips v-if="MULTI" :cfg="cfg" :row="item" compact /><StatusChip v-else v-bind="statusOf(cfg, item)" /></div>
          </template>
        </KanbanBoard>

        <!-- tree -->
        <div v-else-if="view === 'tree'" :class="['cl-tree', { 'cl-busy': refreshing }]">
          <p v-if="filtered && canEdit" class="cl-treenote"><Icon name="info" size="s16" />{{ t('collections.cl_tree_note_filtered') }}</p>
          <p v-else-if="canEdit" class="cl-treenote"><Icon name="info" size="s16" />{{ t('collections.cl_tree_note') }}</p>
          <SortableTree :nodes="treeNodes" :disabled="treeOff" :label="(n) => titleOf(cfg, n)" :aria-label="t('collections.cl_tree_label', { title: cfg.title })" @move="treeMove" @invalid="toast.error">
            <template #default="{ node }">
              <div class="cl-tn">
                <router-link class="cl-link" :to="openPath(node)">{{ titleOf(cfg, node) }}</router-link>
                <span v-if="subLine(node)" class="muted cl-tsub">{{ subLine(node) }}</span>
                <span v-if="kidsCount(node)" class="muted cl-tk" :title="t('collections.cl_nested_count', { count: kidsCount(node) })">{{ kidsCount(node) }}</span>
                <span class="cl-ts"><LangChips v-if="MULTI" :cfg="cfg" :row="node" compact /><StatusChip v-else v-bind="statusOf(cfg, node)" /></span>
              </div>
            </template>
            <template #actions="{ node }">
              <button v-if="canEdit" type="button" class="btn ghost icon sm" :aria-label="t('collections.cl_add_nested_to', { title: titleOf(cfg, node) })" :title="t('collections.cl_add_nested')" @click="add({ parentId: node.id })"><Icon name="plus" size="s16" /></button>
              <button type="button" class="btn ghost icon sm cl-more" :aria-label="t('collections.cl_actions_of', { title: titleOf(cfg, node) })" aria-haspopup="menu" :aria-expanded="menu.open && menu.row && menu.row.id === node.id" @click.stop="openMenu(node, $event)"><Icon name="more" /></button>
            </template>
          </SortableTree>
        </div>

        <!-- table -->
        <div v-else-if="!isCards" :class="['cl-tw', { 'cl-busy': refreshing }]">
          <table class="t t-stack cl-t">
            <thead>
              <tr>
                <th v-if="hasBulk" class="c-ck">
                  <label class="cl-ckl"><input type="checkbox" :checked="allOnPage" :indeterminate.prop="someOnPage && !allOnPage" :aria-label="t('collections.cl_select_page')" @change="tickPage($event.target.checked)"></label>
                </th>
                <th v-if="cfg.thumbField" class="c-th" />
                <th :aria-sort="titleSortable ? ariaSort(cfg.titleField) : undefined">
                  <button v-if="titleSortable" type="button" class="th-s" @click="sortBy(cfg.titleField)">{{ t('collections.cl_col_title') }}<i :class="['th-i', sortDir(sort, cfg.titleField)]" aria-hidden="true" /></button>
                  <template v-else>{{ t('collections.cl_col_title') }}</template>
                </th>
                <th v-for="f in columns" :key="f.k" :class="['c-x', { 'c-num': f.t === 'number' }]" :style="f.list && f.list.width ? { width: f.list.width + 'px' } : undefined" :aria-sort="f.list.sortable ? ariaSort(f.k) : undefined">
                  <button v-if="f.list.sortable" type="button" class="th-s" @click="sortBy(f.k)">{{ f.label }}<i :class="['th-i', sortDir(sort, f.k)]" aria-hidden="true" /></button>
                  <template v-else>{{ f.label }}</template>
                </th>
                <th v-if="cfg.toggleField" class="c-tg">{{ toggleName }}</th>
                <th :aria-sort="!MULTI ? ariaSort('status') : undefined">
                  <button v-if="!MULTI" type="button" class="th-s" @click="sortBy('status')">{{ t('collections.cl_col_status') }}<i :class="['th-i', sortDir(sort, 'status')]" aria-hidden="true" /></button>
                  <template v-else>{{ t('collections.cl_col_languages') }}</template>
                </th>
                <th :aria-sort="ariaSort('updatedAt')"><button type="button" class="th-s" @click="sortBy('updatedAt')">{{ t('collections.cl_col_updated') }}<i :class="['th-i', sortDir(sort, 'updatedAt')]" aria-hidden="true" /></button></th>
                <th class="c-ac"><span class="sr">{{ t('collections.cl_col_actions') }}</span></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in rows" :key="r.id" :class="['click', { 'cl-off': !r.translated, 'cl-picked': selected.has(r.id) }]" @click="onRow($event, r)">
                <td v-if="hasBulk" class="c-ck ts-ck">
                  <label class="cl-ckl"><input type="checkbox" :checked="selected.has(r.id)" :aria-label="t('collections.cl_select_one', { title: titleOf(cfg, r) })" @change="tick(r.id, $event.target.checked)"></label>
                </td>
                <td v-if="cfg.thumbField" class="c-th ts-thumb">
                  <img v-if="thumbOf(r)" class="thumb" :src="thumbUrl(thumbOf(r).src)" alt="" :style="{ objectFit: L.fit, background: '#fff' }">
                  <span v-else class="thumb cl-nophoto"><Icon name="image" size="s16" /></span>
                </td>
                <td class="c-name ts-main">
                  <router-link class="t-title cl-link" :to="openPath(r)">{{ titleOf(cfg, r) }}</router-link>
                  <div v-if="subLine(r)" class="url cl-sub">{{ subLine(r) }}</div>
                </td>
                <td v-for="f in columns" :key="f.k" :class="['c-x ts-sub', { 'c-num num': f.t === 'number' }]" :data-label="f.label"><CellValue :field="f" :value="r[f.k]" /></td>
                <td v-if="cfg.toggleField" class="c-tg ts-sub" :data-label="toggleName">
                  <label class="sw" :title="r.translated ? undefined : t('collections.cl_translate_first_title')">
                    <input type="checkbox" :checked="!!r[cfg.toggleField]" :disabled="!r.translated || !canEdit" @change="toggle(r, $event)">
                    <span class="tr" /><span class="sr">{{ toggleName }}: {{ titleOf(cfg, r) }}</span>
                  </label>
                </td>
                <td :class="['ts-st', { 'c-lg': MULTI && !isCards }]"><LangChips v-if="MULTI" :cfg="cfg" :row="r" :compact="app.languages.list.length > 2" /><StatusChip v-else v-bind="statusOf(cfg, r)" /><span v-if="awaitingOf(r)" class="chip warn cl-wait">{{ t('collections.awaiting') }}</span></td>
                <td class="muted num c-up ts-sub" :data-label="t('collections.cl_col_updated')">{{ r.translated ? fmtUpdated(r.updatedAt) : '—' }}</td>
                <td class="c-ac ts-act">
                  <button type="button" class="btn ghost icon sm cl-more" :aria-label="t('collections.cl_actions_of', { title: titleOf(cfg, r) })" aria-haspopup="menu" :aria-expanded="menu.open && menu.row && menu.row.id === r.id" @click.stop="openMenu(r, $event)"><Icon name="more" /></button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- cards (in the reorder mode: draggable, numbered, with «Раньше» / «Позже»; the cards stop being links) -->
        <div
          v-else ref="gridEl" :class="['cardgrid', { 'cl-busy': refreshing }]" :role="reorder.on ? 'list' : null" :aria-label="reorder.on ? t('collections.cl_order_label', { title: cfg.title }) : null"
          @dragover="onGridOver" @dragleave="onGridLeave" @drop="onGridDrop"
        >
          <div
            v-for="(r, i) in cards" :key="r.id" :data-id="r.id" :role="reorder.on ? 'listitem' : null" :draggable="reorder.on ? 'true' : null"
            :class="['cl-slot', { dragging: reorder.dragId === r.id, 'over-b': reorder.overId === r.id && !reorder.after, 'over-a': reorder.overId === r.id && reorder.after }]"
            @dragstart="onDragStart($event, r)" @dragend="onDragEnd" @dragover="onSlotOver($event, r)"
          >
            <div :class="['amb cl-card', { 'cl-rcard': reorder.on, 'cl-off': !r.translated, 'cl-picked': selected.has(r.id) }]">
              <span v-if="reorder.on" class="cl-pos" aria-hidden="true">{{ i + 1 }}</span>
              <label v-else-if="hasBulk" class="cl-ckl cl-cksel"><input type="checkbox" :checked="selected.has(r.id)" :aria-label="t('collections.cl_select_one', { title: titleOf(cfg, r) })" @change="tick(r.id, $event.target.checked)"></label>
              <img v-if="thumbOf(r)" :src="thumbUrl(thumbOf(r).src, PREVIEW)" alt="" draggable="false" :class="{ logo: L.fit === 'contain' }">
              <span v-else class="cl-cph" :class="{ logo: L.fit === 'contain' }"><Icon name="image" size="s20" /></span>
              <span class="bd">
                <router-link v-if="!reorder.on" class="cl-cname" :to="openPath(r)"><b>{{ titleOf(cfg, r) }}</b></router-link>
                <b v-else>{{ titleOf(cfg, r) }}</b>
                <p v-if="subLine(r)" class="cl-clip">{{ subLine(r) }}</p>
                <span class="so">
                  <LangChips v-if="MULTI" :cfg="cfg" :row="r" compact :interactive="!reorder.on" />
                  <StatusChip v-else v-bind="statusOf(cfg, r)" />
                  <span v-if="awaitingOf(r)" class="chip warn">{{ t('collections.awaiting') }}</span>
                  <template v-if="Array.isArray(r.soc)">
                    <span v-for="s in r.soc" :key="s._id" class="so-c"><Icon name="link" size="s14" />{{ s.net }}</span>
                  </template>
                </span>
              </span>
              <button v-if="!reorder.on" type="button" class="btn icon sm cl-cmore" :aria-label="t('collections.cl_actions_of', { title: titleOf(cfg, r) })" aria-haspopup="menu" :aria-expanded="menu.open && menu.row && menu.row.id === r.id" @click.stop="openMenu(r, $event)"><Icon name="more" /></button>
              <span v-else class="cl-rmv">
                <span class="cl-grip" aria-hidden="true"><Icon name="grip" size="s20" />{{ t('collections.cl_drag') }}</span>
                <button type="button" class="btn ghost icon sm" data-act="earlier" :title="t('collections.cl_earlier')" :aria-label="t('collections.cl_earlier_of', { title: titleOf(cfg, r) })" :disabled="i === 0 || reorder.saving" @click="step(r, -1)"><Icon name="chevL" size="s16" /></button>
                <button type="button" class="btn ghost icon sm" data-act="later" :title="t('collections.cl_later')" :aria-label="t('collections.cl_later_of', { title: titleOf(cfg, r) })" :disabled="i === cards.length - 1 || reorder.saving" @click="step(r, 1)"><Icon name="chevR" size="s16" /></button>
              </span>
            </div>
          </div>
        </div>

        <ListPager v-if="!reorder.on && rows.length && !wide" v-model:page="page" v-model:per-page="perPage" :total="total" />
      </template>
    </div>

    <Popover :open="menu.open" :anchor="menu.anchor" align="right" :width="230" @update:open="closeMenu">
      <button type="button" class="mi" @click="act(open)"><Icon :name="!canEdit ? 'eye' : menu.row && !menu.row.translated ? 'globe' : 'edit'" size="s16" />{{ !canEdit ? t('collections.cl_menu_view') : menu.row && !menu.row.translated ? t('collections.cl_menu_add_translation') : t('collections.cl_menu_open') }}</button>
      <button v-if="canEdit" type="button" class="mi" @click="act(duplicate)"><Icon name="copy" size="s16" />{{ t('collections.cl_menu_duplicate') }}</button>
      <template v-if="rowActions.length">
        <hr>
        <button v-for="a in rowActions" :key="a.key" type="button" :class="['mi', { danger: a.danger }]" @click="runRow(a)"><Icon :name="iconOf(a) || 'chevR'" size="s16" />{{ a.label }}</button>
      </template>
      <hr v-if="canDelete">
      <button v-if="canDelete" type="button" class="mi danger" @click="act(remove)"><Icon name="trash" size="s16" />{{ t('collections.delete') }}</button>
    </Popover>

    <ActionDialog :actions="actions" />
  </div>
</template>

<style scoped>
.cl-sheet { overflow: hidden; }
.cl-wait { margin-left: 6px; }
.cl-add { display: flex; flex-direction: column; align-items: flex-end; gap: 5px; }
.cl-addnote { font-size: var(--t-xs); color: var(--ink-3); max-width: 300px; text-align: right; line-height: 1.35; }
.cl-addnote.c { text-align: center; max-width: 44ch; }
/* a record without a version in this language: named by its main-language title, quieter than the translated ones */
.cl-off .cl-link, .cl-off .cl-cname { color: var(--ink-2); }
.cl-off .thumb, .cl-card.cl-off img, .cl-card.cl-off .cl-cph { opacity: .6; }
.cl-key { display: flex; gap: 16px; flex-wrap: wrap; padding: 8px 14px; font-size: var(--t-xs); color: var(--ink-2); border-bottom: 1px solid var(--hair-2); background: var(--sheet-2); }
.cl-k { display: inline-flex; align-items: center; gap: 6px; }
.cl-k i { width: 8px; height: 8px; border-radius: 50%; background: var(--green); }
.cl-k.chg i { background: var(--mag); }
.cl-k.off i { background: transparent; box-shadow: inset 0 0 0 1.5px var(--ctl); }
.cl-pad { padding: 18px 22px; }
.cl-tree { padding: 14px; }
.cl-treenote { display: flex; gap: 8px; align-items: flex-start; margin: 0 0 12px; max-width: 78ch; font-size: var(--t-sm); line-height: 1.45; color: var(--ink-2); }
.cl-treenote .ic { flex: none; margin-top: 2px; color: var(--brand-text); }
.cl-tn { display: flex; align-items: center; gap: 12px; min-width: 0; }
.cl-tn .cl-link { font-weight: 600; }
.cl-tsub { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: var(--t-sm); }
.cl-tk { font-size: var(--t-xs); font-variant-numeric: tabular-nums; }
.cl-ts { margin-left: auto; flex: none; }
.kb-t { font-weight: 600; white-space: normal; }
.kb-s { margin-top: 6px; }
.cl-t .c-th { width: 70px; }
.cl-t .c-ac { width: 56px; text-align: right; }
.cl-t .c-tg { width: 120px; }
.cl-t th.c-tg { white-space: nowrap; }
.cl-t .c-up { white-space: nowrap; width: 170px; }
.cl-t .c-name { max-width: 0; width: 46%; }
.cl-t .c-lg { white-space: nowrap; }
.cl-t .c-lg :deep(.lcs) { flex-wrap: nowrap; }
.cl-link { color: var(--ink); text-decoration: none; display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
.cl-link:hover { color: var(--brand-text); text-decoration: underline; }
.cl-sub { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cl-t .thumb { width: 48px; height: 48px; display: block; }
.cl-nophoto { display: grid; place-items: center; color: var(--ink-3); }
.cl-more, .cl-cmore { color: var(--ink-2); }
.cl-more :deep(.ic), .cl-cmore :deep(.ic) { stroke-width: 3.2; }
.cl-more:hover, .cl-more[aria-expanded="true"] { color: var(--brand-text); }

/* cards: the whole card opens the record (stretched link), the menu button sits above it */
.cl-card { position: relative; }
.cl-card:focus-within { box-shadow: var(--sh-2); border-color: var(--ctl); }
.cl-cname { color: var(--ink); text-decoration: none; }
.cl-cname::after { content: ""; position: absolute; inset: 0; }
.cl-cname:hover b { color: var(--brand-text); }
.cl-card img.logo { object-fit: contain; background: #fff; padding: 18px; aspect-ratio: 16/9; }
.cl-cph { aspect-ratio: 4/3; display: grid; place-items: center; background: var(--hair-2); color: var(--ink-3); }
.cl-cph.logo { aspect-ratio: 16/9; }
.cl-clip { display: -webkit-box; -webkit-line-clamp: 3; line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.cl-cmore { position: absolute; top: 8px; right: 8px; z-index: 1; background: rgba(255,255,255,.92); }
.cl-card .so { align-items: center; }

/* the grid item is the slot: it carries the drop mark, which has to stay outside the card (the card clips its content) */
.cl-slot { position: relative; display: flex; }
.cl-slot > .cl-card { flex: 1; min-width: 0; }

/* reorder mode */
.cl-rbar { position: sticky; top: 12px; z-index: 5; display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding: 10px 12px 10px 16px; background: var(--cy-50); border: 1px solid var(--cy-200); border-radius: var(--r-lg); color: var(--cy-dd); box-shadow: var(--sh-2); }
.cl-rbar p { flex: 1; font-weight: 550; line-height: 1.35; }
.cl-rcard { cursor: grab; user-select: none; -webkit-user-select: none; }
.cl-rcard:hover { border-color: var(--cy); }
.cl-slot.dragging .cl-card { opacity: .4; }
.cl-slot.over-b::before, .cl-slot.over-a::after { content: ""; position: absolute; top: -4px; bottom: -4px; width: 4px; border-radius: 2px; background: var(--cy); z-index: 3; pointer-events: none; }
.cl-slot.over-b::before { left: -9px; }
.cl-slot.over-a::after { right: -9px; }
.cl-pos { position: absolute; top: 8px; left: 8px; z-index: 1; min-width: 28px; height: 28px; padding: 0 8px; border-radius: 14px; display: grid; place-items: center; background: var(--cy); color: var(--brand-ink); font-size: var(--t-sm); font-weight: 650; font-variant-numeric: tabular-nums; box-shadow: 0 0 0 2px #fff; }
.cl-rmv { display: flex; align-items: center; gap: 2px; padding: 6px 8px 6px 12px; border-top: 1px solid var(--hair-2); background: var(--sheet-2); }
.cl-grip { display: inline-flex; align-items: center; gap: 4px; margin-right: auto; color: var(--ink-3); font-size: var(--t-xs); font-weight: 560; }
.cl-rmv .btn { cursor: pointer; }
/* the shared spinner is white (for primary buttons); on the white «Изменить порядок» it needs the grey/cyan one */
.page-h .btn.loading:not(.primary)::after { border-color: var(--hair); border-top-color: var(--cy); }

/* search + filters */
.cl-bar { align-items: flex-end; }
.cl-bar .search { flex: none; width: 240px; }
.cl-bar .search input { height: 32px; font-size: var(--t-sm); }
.cl-qi { display: flex; flex-direction: column; gap: 3px; }
.lf-l { font-size: var(--t-xs); font-weight: 580; color: var(--ink-2); line-height: 1.2; }
.cl-sortsel { width: auto; height: 32px; min-width: 170px; font-size: var(--t-sm); }
.cl-reset { align-self: flex-end; }
.cl-bar .grow { align-self: center; }
.cl-views { width: auto; align-self: flex-end; background: var(--hair-2); }
.cl-views button { color: var(--ink-2); height: 28px; padding: 0 12px; }
.cl-views button:hover { color: var(--ink); }
.cl-views button[aria-pressed=true] { background: #fff; color: var(--brand-text); box-shadow: var(--sh-1); }
.cl-busy { opacity: .6; transition: opacity var(--dur); pointer-events: none; }

/* sortable column headers */
.cl-t th .th-s { display: inline-flex; align-items: center; gap: 6px; padding: 0; border: 0; background: none; font: inherit; color: inherit; letter-spacing: inherit; }
.cl-t th .th-s:hover { color: var(--brand-text); }
.th-i { width: 8px; height: 10px; position: relative; flex: none; opacity: .35; }
.th-i::before, .th-i::after { content: ""; position: absolute; left: 0; border: 4px solid transparent; }
.th-i::before { top: -2px; border-bottom: 4px solid currentColor; border-top: 0; }
.th-i::after { bottom: -2px; border-top: 4px solid currentColor; border-bottom: 0; }
.th-i.asc, .th-i.desc { opacity: 1; color: var(--brand-text); }
.th-i.asc::after, .th-i.desc::before { display: none; }
.cl-t .c-x { white-space: nowrap; max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
.cl-t .c-num { text-align: right; }
.cl-t .c-ck { width: 44px; padding-right: 0; }
.cl-tw { overflow-x: auto; }

/* ticks and the bar of ticked rows */
.cl-ckl { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; cursor: pointer; }
.cl-ckl input { width: 16px; height: 16px; accent-color: var(--cy); margin: 0; cursor: pointer; }
.cl-picked, .cl-t tr.cl-picked { background: var(--cy-50); }
.cl-sel { position: sticky; top: 12px; z-index: 5; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; padding: 8px 12px; background: var(--cy-50); border: 1px solid var(--cy-200); border-radius: var(--r-lg); color: var(--cy-dd); box-shadow: var(--sh-2); }
.cl-sel b { margin-right: 6px; }
.cl-sel-x { margin-left: auto; }
.cl-cksel { position: absolute; top: 8px; left: 8px; z-index: 2; background: rgba(255,255,255,.92); border-radius: 6px; }

/* phone */
@media (max-width: 767px) {
  .cl-t .c-name { max-width: none; width: auto; }
  .cl-t .c-th, .cl-t .c-tg, .cl-t .c-up, .cl-t .c-ac, .cl-t .c-ck { width: auto; }
  .cl-t .c-x { max-width: none; white-space: normal; }
  .cl-t .c-lg { white-space: normal; }
  .cl-t .c-lg :deep(.lcs) { flex-wrap: wrap; }
  .cl-link { white-space: normal; }
  .cl-t .thumb { width: 56px; height: 56px; }
  .cl-bar { align-items: stretch; }
  .cl-bar .cl-qi { flex: 1 1 100%; }
  .cl-bar .search { width: 100%; }
  .cl-bar .search input { height: 44px; font-size: 16px; }
  .cl-bar .cl-views { align-self: stretch; flex: 1 1 100%; width: auto; }
  .cl-bar .cl-views button { flex: 1; height: 44px; }
  .cl-bar .cl-reset, .cl-bar .grow { align-self: center; }
  .cl-bar .cl-reset { display: none; } /* the sheet has «Сбросить» */
  .cl-bar .grow { flex: 1 1 100%; margin-left: 0; }
  .cl-sortsel { min-width: 0; width: 100%; height: 44px; font-size: 16px; }
  .cl-add { flex: 1 1 100%; align-items: stretch; }
  .cl-addnote { max-width: none; text-align: left; }
  .cl-key { padding: 8px 12px; }
  .cardgrid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding: 12px; }
  .cl-card .bd { padding: 9px 10px 10px; }
  .cl-ckl { width: 44px; height: 44px; }
  .cl-ckl input { width: 20px; height: 20px; }
  .cl-cksel { top: 2px; left: 2px; }
  .cl-cmore { top: 4px; right: 4px; }
  /* the ticked rows: a bar at the bottom edge, in reach of the thumb */
  .cl-sel { position: fixed; z-index: 120; left: 12px; right: 12px; top: auto; bottom: calc(14px + env(safe-area-inset-bottom)); margin: 0; padding: 10px 12px; box-shadow: var(--sh-3); }
  .cl-sel b { flex: 1 1 100%; }
  .cl-sel .btn { flex: 1 1 auto; }
  .cl-sel-x { margin-left: 0; }
  .cl-rbar { top: calc(60px + env(safe-area-inset-top)); flex-wrap: wrap; padding: 10px 12px; }
  .cl-rbar p { flex: 1 1 100%; }
  .cl-rbar .btn { flex: 1 1 auto; }
  .cl-rmv { padding: 4px 6px 4px 10px; }
  .cl-tree { padding: 10px; }
  .cl-tn { flex-wrap: wrap; gap: 2px 8px; }
  .cl-ts { margin-left: 0; }
}
</style>

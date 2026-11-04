// What a list of a collection is made of, worked out from the schema the server sent (cfg): the filters, the sortable columns, the views. Pure code, no api.
//
// filters   the status filter (built in, every collection has it) + cfg.filters (fields with filterable()); each: { k, label, kind, options, field }
// columns   the title column comes first and is not here; then every field the schema shows in the list: list.show === true, or list.show === null and the field is sortable
// views     table, cards, calendar, kanban, tree (viewsOf); the last three load every record at once (WIDE_VIEWS)
// sorts     keys the server accepts for `sort=`: the sortable fields + status / updatedAt / position
import { t } from '@/i18n/index.js'
import { dataFields } from '../labels.js'

export const KINDS = ['text', 'select', 'multi', 'toggle', 'dateRange', 'numberRange', 'relation']

/** The built in status filter: the same words as the chips of the list. `missing` = no version in the content language (a site with 2+ languages). */
export const statusFilter = (missingLabel = '') => ({
  k: 'status', label: t('collections.filter_status'), kind: 'select', field: null,
  options: [['published', t('collections.filter_published')], ['draft', t('collections.filter_drafts')], ...(missingLabel ? [['missing', missingLabel]] : [])],
})

/** Filters of the list from the schema: [{ k, label, kind, options, field }]. A kind the client does not know is dropped. */
export function filtersOf(cfg, missingLabel = '') {
  const own = (cfg.filters || [])
    .filter((f) => f && f.filter && KINDS.includes(f.filter.kind))
    .map((f) => ({ k: f.k, label: f.label, kind: f.filter.kind, options: f.filter.options || [], field: f }))
  return [statusFilter(missingLabel), ...own.filter((f) => f.k !== 'status')]
}

/** Fields shown as extra columns of the table (not the title). */
export function columnsOf(cfg) {
  return dataFields(cfg).filter((f) => f.k !== cfg.titleField && f.form !== false && (f.list ? f.list.show === true || (f.list.show == null && f.list.sortable) : false) && f.list.show !== false)
}

/** Sort keys the server takes. */
export function sortKeysOf(cfg) {
  return ['status', 'updatedAt', 'position', ...dataFields(cfg).filter((f) => f.list && f.list.sortable).map((f) => f.k)]
}

/** Views of the list this admin can draw: the schema lists them (views); a collection with tree() also has «Структура». */
export const SUPPORTED_VIEWS = ['table', 'cards', 'calendar', 'kanban', 'tree']
/** Views that show ALL the records at once (no pages): the calendar, the board, the tree. */
export const WIDE_VIEWS = ['calendar', 'kanban', 'tree']
export function viewsOf(cfg) {
  const list = (cfg.views || []).map((v) => (typeof v === 'string' ? v : v && v.type)).filter((t) => SUPPORTED_VIEWS.includes(t) && t !== 'tree')
  const own = list.length ? [...new Set(list)] : [cfg.view === 'cards' ? 'cards' : 'table']
  return cfg.tree ? ['tree', ...own] : own
}
export const VIEW_LABELS = { table: t('collections.view_table'), cards: t('collections.view_cards'), calendar: t('collections.view_calendar'), kanban: t('collections.view_kanban'), tree: t('collections.view_tree') }
/** The settings of one view from the schema ({ type, start, end, title } of a calendar, { type, field, columns } of a board), or null. */
export const viewConfig = (cfg, type) => (cfg.views || []).find((v) => v && typeof v === 'object' && v.type === type) || null

/** Is a value of a filter set? */
export function isSet(value) {
  if (value == null || value === '' || value === false) return false
  if (Array.isArray(value)) return value.length > 0
  if (typeof value === 'object') return Object.values(value).some(isSet)
  return true
}

/** The filter without empty values (what is sent and what is saved). */
export function cleanFilter(filter) {
  const out = {}
  Object.entries(filter || {}).forEach(([k, v]) => {
    if (!isSet(v)) return
    out[k] = typeof v === 'object' && !Array.isArray(v) ? Object.fromEntries(Object.entries(v).filter(([, x]) => isSet(x))) : v
  })
  return out
}

/** How many filters are set. */
export const activeCount = (filter) => Object.keys(cleanFilter(filter)).length

/** A saved value from the server made safe for this schema: unknown filters, sort keys and views are dropped (the schema may have changed since it was saved). */
export function sanitizeSaved(saved, cfg, filters) {
  const out = { filter: {}, sort: '', perPage: 0, view: '' }
  if (!saved || typeof saved !== 'object') return out
  const kinds = Object.fromEntries(filters.map((f) => [f.k, f.kind]))
  if (saved.filter && typeof saved.filter === 'object') {
    Object.entries(saved.filter).forEach(([k, v]) => {
      if (!(k in kinds)) return
      const range = kinds[k] === 'dateRange' || kinds[k] === 'numberRange'
      const multi = kinds[k] === 'multi' || kinds[k] === 'relation'
      if (range && v && typeof v === 'object' && !Array.isArray(v)) out.filter[k] = { from: v.from ?? '', to: v.to ?? '' }
      else if (multi && Array.isArray(v)) out.filter[k] = v.map(String)
      else if (!range && !multi && typeof v !== 'object') out.filter[k] = String(v)
    })
  }
  const keys = sortKeysOf(cfg)
  if (typeof saved.sort === 'string' && saved.sort && keys.includes(saved.sort.replace(/^-/, ''))) out.sort = saved.sort
  if (Number.isInteger(saved.perPage) && saved.perPage > 0) out.perPage = Math.min(200, saved.perPage)
  if (typeof saved.view === 'string' && viewsOf(cfg).includes(saved.view)) out.view = saved.view
  return out
}

/** Next sort for a click on a column header: none → ascending → descending → none. */
export function nextSort(current, key) {
  if (current === key) return '-' + key
  if (current === '-' + key) return ''
  return key
}
export const sortDir = (current, key) => (current === key ? 'asc' : current === '-' + key ? 'desc' : '')

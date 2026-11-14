// Generic wording and small helpers shared by the list, the item form and the options matrix. The words do not depend on the collection: the schema names it.
import { collectionFields } from '@/schema/collections.js'
import { clone } from '@/utils/objects.js'
import { t } from '@/i18n/index.js'
import { nid } from '@/utils/ids.js'

// Generic wording for any collection. A site's own nouns live in its schema (title, singular, newLabel, description, empty), never here.
const GENERIC = {
  newTitle: '', untitled: t('collections.untitled'), pubLabel: t('collections.published'), search: t('collections.search'),
  fit: 'cover', first: t('collections.first_unpublished'), del: t('collections.delete_record'),
  delMsg: t('collections.delete_message'),
  orderHint: t('collections.order_hint'), orderNote: t('collections.order_note'),
  asideTitle: t('collections.aside_title'), asideHint: t('collections.aside_hint'),
}
export const labelsOf = () => GENERIC

/** «12 записей» */
export const countText = (key, n) => t('collections.count_records', { count: n })

/** The name of a row's switch: cfg.toggleLabel when a collection gives one, else the label of the field cfg.toggleField (the server sends only the key), a switch without a name is «Показывать». */
export const toggleLabelOf = (cfg) => {
  const field = collectionFields(cfg).find((f) => f.k === cfg.toggleField)
  return cfg.toggleLabel || (field && field.label) || t('collections.toggle_default')
}

/** The toast after a row's switch («В каталоге», «На сайте») was turned: `label` is its name (toggleLabelOf), a switch without a name is «Показывать». */
export const toggleNote = (title, label, on) => t(on ? 'collections.toggle_will_on' : 'collections.toggle_will_off', { title, label: label || t('collections.toggle_default') })

/** «3 правки» */
export const changesText = (n) => t('collections.count_changes', { count: n })

/** Fields of the collection that hold data (every tab, readonly skipped). */
export const dataFields = (cfg) => collectionFields(cfg).filter((f) => f.t !== 'readonly')

/** Only the schema fields of a record: what the autosave sends (no id / status / updatedAt). */
export function pickFields(cfg, rec) {
  const o = {}
  dataFields(cfg).forEach((f) => { if (rec[f.k] !== undefined) o[f.k] = clone(rec[f.k]) })
  return o
}

/** Display title of a record, with a fallback for an empty one. */
export const titleOf = (cfg, rec) => String((rec && rec[cfg.titleField]) || '').trim() || labelsOf(cfg.key).untitled

/** Give every repeater item of a copied record a fresh _id (ids must stay unique inside one record). */
export function freshIds(v) {
  if (Array.isArray(v)) v.forEach(freshIds)
  else if (v && typeof v === 'object') {
    if ('_id' in v) v._id = nid('p')
    Object.values(v).forEach(freshIds)
  }
  return v
}

/** Status chip props of a list row / a record: { status, label }. */
export function statusOf(cfg, rec) {
  if (rec.status === 'missing') return { status: 'missing', label: t('collections.state_missing') } // no version in the content language
  if (rec.status === 'draft') return { status: 'draft', label: t('collections.state_draft') }
  if (rec.changed) return { status: 'changed', label: changesText(rec.changes) }
  return { status: 'published', label: labelsOf(cfg.key).pubLabel }
}

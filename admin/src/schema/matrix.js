// The wording of the options matrix (a collection with view 'matrix': one record { cols, rows }, a table of options by columns and a price row). The words are the collection's, not the admin's:
// a site that sells tariffs says «тариф» and «опция», another says «пакет» and «услуга». They come from the schema of the collection:
//
//   cfg.newLabel                        the label of the button that adds a column («Добавить тариф»): a verb, never the name of the column; cfg.description  the line under the heading
//   cfg.matrix.colFields[1].label       the name of the price row («Цена, ₽ в месяц»)
//   cfg.matrix.words                    { key: phrase } for any key of MATRIX_WORDS below; a phrase with {name} gets the name of the column / row put in (fill)
//
// Without any of them the matrix speaks in general words (столбец, строка). Pure code, no Vue.

import { t } from '@/i18n/index.js'

export const MATRIX_WORDS = {
  about: t('forms.matrix_about'), // the line under the heading
  subject: t('forms.matrix_subject'), // «Опубликовать изменения ___»
  what: t('forms.matrix_what'), // «Вы можете только смотреть ___»
  ariaLabel: t('forms.matrix_aria_label'), // the name of the table for screen readers
  first: t('forms.matrix_first'), // the one entry of the first publication
  corner: t('forms.matrix_corner'), // the head of the first column
  group: t('forms.matrix_group'), // the row that opens the rows
  colNew: t('forms.matrix_col_new'), // the name a new column starts with (matrix.words only: cfg.newLabel is a button)
  colAdd: t('forms.matrix_col_add'), // the button (cfg.newLabel first)
  colPlural: [t('forms.matrix_col_one'), t('forms.matrix_col_few'), t('forms.matrix_col_many')],
  colName: t('forms.matrix_col_name'), // for screen readers
  colNote: t('forms.matrix_col_note'),
  colRemove: t('forms.matrix_col_remove'),
  colRemoveAsk: t('forms.matrix_col_remove_ask'),
  colRemoveText: t('forms.matrix_col_remove_text'),
  colNeedName: t('forms.matrix_col_need_name'),
  colsTitle: t('forms.matrix_cols_title'), // the head of the part in the list of changes of a record
  colsUnit: '', // empty: the count in the list of changes comes from forms.matrix_cols_count (a form by the number); a collection may name the unit itself
  colAdded: t('forms.matrix_col_added'), // the lines of the list of changes
  colRenamed: t('forms.matrix_col_renamed'),
  colRemoved: t('forms.matrix_col_removed'),
  priceLabel: t('forms.matrix_price_label'), // the name of the price row (colFields[1].label first); in changes: «Цена «Про»»
  priceHint: t('forms.matrix_price_hint'),
  priceEmpty: t('forms.matrix_price_empty'),
  noteChanged: t('forms.matrix_note_changed'),
  rowNew: t('forms.matrix_row_new'),
  rowAdd: t('forms.matrix_row_add'),
  rowPlural: [t('forms.matrix_row_one'), t('forms.matrix_row_few'), t('forms.matrix_row_many')],
  rowName: t('forms.matrix_row_name'),
  rowNone: t('forms.matrix_row_none'),
  rowRemoved: t('forms.matrix_row_removed'),
  rowNeedName: t('forms.matrix_row_need_name'),
  rowsTitle: t('forms.matrix_rows_title'),
  rowsUnit: '', // the same for the rows: forms.matrix_rows_count
  rowAdded: t('forms.matrix_row_added'),
  rowRenamed: t('forms.matrix_row_renamed'),
  rowGone: t('forms.matrix_row_gone'),
  rowKind: t('forms.matrix_row_kind'),
  rowOrder: t('forms.matrix_row_order'),
  copyHint: t('forms.matrix_copy_hint'), // «Скопировать с русского» for a language without a table
}

/** `phrase` with {name} replaced by `name`. */
export const fill = (phrase, vars = {}) => String(phrase).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

const isPlural = (v) => Array.isArray(v) && v.length === 3 && v.every((x) => typeof x === 'string')

/** All the words of the matrix of this collection: MATRIX_WORDS, then what the collection says (see the top). A value of the wrong type in `matrix.words` is dropped. */
export function matrixWords(cfg) {
  const c = cfg || {}
  const m = c.matrix || {}
  const out = { ...MATRIX_WORDS }
  if (typeof c.newLabel === 'string' && c.newLabel.trim()) out.colAdd = c.newLabel
  if (typeof c.description === 'string' && c.description.trim()) out.about = c.description
  const price = Array.isArray(m.colFields) && m.colFields[1] && m.colFields[1].label
  if (typeof price === 'string' && price.trim()) out.priceLabel = price
  Object.entries(m.words || {}).forEach(([k, v]) => {
    if (!(k in MATRIX_WORDS)) return
    if (Array.isArray(MATRIX_WORDS[k]) ? isPlural(v) : typeof v === 'string') out[k] = v
  })
  return out
}

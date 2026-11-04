// Words of the history: what an entry says about itself. The server writes the rows of changes; the label of the entry as a whole is chosen here.
import { t } from '@/i18n/index.js'

const ACTIONS = ['create', 'edit', 'publish', 'unpublish', 'revert', 'delete', 'restore']

/** «Правка» / «Опубликовано на сайте» / «Версия до начала истории» */
export const actionLabel = (e) => (e.baseline ? t('ui.history_action_baseline') : ACTIONS.includes(e.action) ? t(`ui.history_action_${e.action}`) : t('ui.history_action_other'))

/** Who did it: a person's name, or «Система» (an import, a console command). A change made by an AI assistant says so: «Анна через ИИ-ассистента (Claude Desktop)». */
export const whoLabel = (e) => {
  const who = e.userName || t('ui.system')
  if (e.via !== 'mcp') return who
  return e.viaName ? t('ui.history_via_named', { name: who, via: e.viaName }) : t('ui.history_via', { name: who })
}

/** The first rows of an entry as one line: «Заголовок, Цена и ещё 2». Rows without a field name (the whole-object marks) are left out. */
export function shortSummary(rows, max = 2) {
  const names = []
  ;(rows || []).forEach((r) => {
    if (r.kind === 'more') return
    const label = r.kind === 'field' || r.kind === 'edit' || r.kind === 'meta' ? r.label : ''
    if (label && !names.includes(label)) names.push(label)
  })
  if (!names.length) return ''
  const shown = names.slice(0, max).join(', ')
  return names.length > max ? t('ui.history_more_fields', { names: shown, more: names.length - max }) : shown
}

/** The things a history can be of: each has its own sentences in the catalogue (ui.history_restore_msg_<kind>, ui.history_empty_<kind>). */
export const KINDS = ['page', 'item', 'settings', 'menu']
export const kindOf = (kind) => (KINDS.includes(kind) ? kind : 'page')

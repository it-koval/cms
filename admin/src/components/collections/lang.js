// Language helpers of the collection screens: the word on a language chip and the one-tab memory
// that survives a content-language switch. Pure code, no api. The grammar of language names («английскую версию», «на английском») is shared:
// utils/langWords.js.
import { t } from '@/i18n/index.js'
import { changesText, labelsOf } from './labels.js'

/** The word on a chip for a state: «Опубликован» in the right gender, «2 правки», «Черновик», «Нет перевода». */
export function stateWord(cfgKey, state, changes = 0) {
  if (state === 'missing') return t('collections.state_missing')
  if (state === 'draft') return t('collections.state_draft')
  if (state === 'changed') return changes > 0 ? changesText(changes) : t('collections.state_changed')
  return labelsOf(cfgKey).pubLabel
}

/* ---------- a tab picked on the record screen survives the content-language switch (the screen is re-mounted by the switch) ---------- */
let carried = null
/** Call right before switching the language: the next record screen opens the same tab and puts the focus back on the language select. */
export const carryTab = (col, id, tab) => { carried = { col, id, tab, at: Date.now() } }
/** The tab carried by a switch just made (or null). Read once, then forgotten. */
export function takeTab(col, id) {
  const c = carried
  carried = null
  return c && c.col === col && c.id === id && Date.now() - c.at < 5000 ? c : null
}

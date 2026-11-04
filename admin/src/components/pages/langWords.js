// What the client reads for the state of one language version of a PAGE («Опубликована», «Есть правки · 2», «Черновик», «Нет перевода»).
// The grammar («английскую версию», «на английском») lives in utils/langWords.js, shared by every screen. The long sentence of a state is in LangChips.vue (pages.chip_hint_<key>).
import { t } from '@/i18n/index.js'
import { STATE_LABEL, version, versionState } from '@/utils/langWords.js'

/** `v` = a row of pages.list().locales[code] (or null = no version): { key, label }. */
export function stateOf(v) {
  const key = versionState(v)
  return { key, label: STATE_LABEL[key] }
}

/**
 * The form of «английская версия» that stands next to «нет» / «no» / «жоқ»: genitive in Russian (Нет английской версии), nominative in English and Kazakh (Ағылшын нұсқасы жоқ).
 * The catalogue names the case (pages.version_none = 'gen' | 'nom': a code value, not a text to translate), so the grammar stays out of the code.
 */
export function versionNone(code) {
  const v = version(code)
  return v[t('pages.version_none')] ?? v.nom
}

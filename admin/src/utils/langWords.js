// Wording about content languages, in ONE place: «Опубликовать английскую версию» in Russian, "English version" in English, «ағылшын нұсқасын» in Kazakh, never «EN» or «en».
// Used by every screen that talks about languages (pages, editor, collections, settings, today). Pure functions + the reactive language names of utils/locales.js.
// The words follow the language of the INTERFACE (i18n/index.js), not the language of the content. Each form is a text of the catalogue (core.lang_*) with the stem of the language in it,
// so the grammar of a language lives in its own catalogue: Russian cases (-ая -ой -ую -ом), Kazakh possessive endings (нұсқасы -ның -н), English word order.
// A form is a part of a longer sentence: a caller puts it into a text of its own with a parameter and never glues pieces of a sentence together.
// English forms carry no article: the sentence of the caller says «the» where it needs one. Capitalise a form that starts a sentence with cap().
//
//   version('en')   { nom, gen, acc }          ru: 'английская версия', 'английской версии', 'английскую версию';  en: 'English version' (three times);  kk: 'ағылшын нұсқасы', 'ағылшын нұсқасының', 'ағылшын нұсқасын'
//   verNom / verGen / verAcc  the same three forms as functions
//   onLang('en')    ru «на английском»      en «in English»       kk «ағылшын тілінде»       Texts in English · 6 of 12 in English
//   toLang('en')    ru «на английский»      en «into English»     kk «ағылшын тіліне»        No translation into English
//   fromLang('ru')  ru «с русского»         en «from Russian»     kk «орыс тілінен»          Copy from Russian
//   siteIn('en')    ru «в английской версии сайта»   en «in the English version of the site»   kk «сайттың ағылшын нұсқасында»
//   adjF('en')      ru «английская»         en «English»          kk «ағылшын»
//   nameIn('en')    ru «английское название»   en «English name»   kk «ағылшын атауы»
//   cap('русская')  «Русская»                  (up is the same function)
//
// A language the table does not know (the client may add any code in Settings → Languages) falls back to its NAME from Settings → Languages in quotes:
// «версию «Esperanto»», «на языке «Esperanto»». The names of languages of the catalog (en, de, es, fr, it, kk, uk, zh) and ru are known.
import { t } from '@/i18n/index.js'
import { langName } from '@/utils/locales.js'

// the languages the stems core.lang_stem_<code> are written for
const KNOWN = ['ru', 'en', 'de', 'es', 'fr', 'it', 'kk', 'uk', 'zh']
const stem = (code) => (KNOWN.includes(code) ? t(`core.lang_stem_${code}`) : null)

export const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : s)
export const up = cap

const MARK = '\u0000'
/**
 * t() that capitalises one parameter only where the text of the CURRENT language puts it at the start of a sentence (at the start of the text or after «. », «! », «? »).
 * For a text whose sentence order differs by language: «… «Опубликовать». Английская версия страницы…» in Russian, «…, the English version of …» in English, «… бетінің ағылшын нұсқасы …» in Kazakh.
 * cap(t()) would capitalise the start of the whole text, cap() on the parameter would put a capital in the middle of a Kazakh sentence.
 */
export function capParam(key, params, name) {
  const parts = t(key, { ...params, [name]: MARK }).split(MARK)
  const value = String(params[name])
  return parts.reduce((out, part, i) => (i === 0 ? part : out + (/(^|[.!?…]\s+)$/.test(out) ? cap(value) : value) + part), '')
}

/** «английская версия» in the three cases the screens need: nom, gen, acc. */
export function version(code) {
  const lang = stem(code)
  const name = langName(code)
  if (lang) return { nom: t('core.lang_version_nom', { lang }), gen: t('core.lang_version_gen', { lang }), acc: t('core.lang_version_acc', { lang }) }
  return { nom: t('core.lang_version_nom_other', { name }), gen: t('core.lang_version_gen_other', { name }), acc: t('core.lang_version_acc_other', { name }) }
}
export const verNom = (code) => version(code).nom
export const verGen = (code) => version(code).gen
export const verAcc = (code) => version(code).acc

/** «на английском» */
export const onLang = (code) => (stem(code) ? t('core.lang_on', { lang: stem(code) }) : t('core.lang_on_other', { name: langName(code) }))
/** «на английский» (a direction: «Без перевода на английский») */
export const toLang = (code) => (stem(code) ? t('core.lang_to', { lang: stem(code) }) : t('core.lang_to_other', { name: langName(code) }))
/** «с русского» (copy from) */
export const fromLang = (code) => (stem(code) ? t('core.lang_from', { lang: stem(code) }) : t('core.lang_from_other', { name: langName(code) }))
/** «в английской версии сайта» */
export const siteIn = (code) => (stem(code) ? t('core.lang_site_in', { lang: stem(code) }) : t('core.lang_site_in_other', { name: langName(code) }))
/** «русская» — the adjective alone, feminine nominative in Russian (for a longer phrase) */
export const adjF = (code) => (stem(code) ? t('core.lang_adj', { lang: stem(code) }) : langName(code))
/** «русское название» / «название на языке «Esperanto»» — the name of a page in a language (shown instead of a missing translation) */
export const nameIn = (code) => (stem(code) ? t('core.lang_name_in', { lang: stem(code) }) : t('core.lang_name_in_other', { name: langName(code) }))

/** Why the home version of a non-main language cannot be deleted while other pages of that language are on the site. Used by the api (409) and by the menus that disable the action. */
export const homeHeldText = (code) => cap(t('core.lang_home_held', { version: verAcc(code), on: onLang(code) }))

/* ---------- the state of one language version (shared by the chips, tabs and dots of every screen) ---------- */

/**
 * 'published' | 'changed' (published, with unpublished changes) | 'draft' (never published) | 'missing' (no version).
 * `s` = a `locales[code]` summary of the api (pages.list / collections.list / getDraft / get): { status, changed, changes } or null.
 */
export function versionState(s) {
  if (!s) return 'missing'
  if (s.status === 'draft') return 'draft'
  return s.changed ? 'changed' : 'published'
}

/** What the client reads for each state of a language version (Published, Has edits, Draft, No translation): pages / records / the language select. */
export const STATE_LABEL = { published: t('core.lang_state_published'), changed: t('core.lang_state_changed'), draft: t('core.lang_state_draft'), missing: t('core.lang_state_missing') }

// Plain reactive store (no Pinia): the site, its content languages, the signed-in user and the numbers shown in the rail.
//   import { app, can, refreshCounters, setContentLocale, switchContentLocale, useLocaleGuard, reloadLanguages } from '@/stores/app.js'
//   app.siteInfo        { key, name, domain } from window.ITK_ADMIN.site
//   app.languages       { main:'ru', list:[{ code, name }] } (GET languages; the first iteration has the Russian language only: the language UI hides itself for one language)
//   app.locale          the CURRENT CONTENT LANGUAGE: the language Pages, Collections, Settings texts and Menus work in. Remembered in this browser (localStorage), falls back to the main language.
//                       Change it with setContentLocale(code); views that depend on it are re-mounted by App.vue (route.meta.byLocale), the page editor handles it itself.
//                       A SWITCH THE USER MAKES (rail, tabs, Settings switcher) goes through switchContentLocale(code): it asks the screen first (useLocaleGuard),
//                       so unsaved edits are saved or the user is asked before the screen is re-mounted and forgets them.
//   app.nav             the left rail as the server built it for this person: [{ key, group, icon, order, items:[{ key, label, to, icon, order, badge?, sub? }] }] (GET schema → nav)
//   app.badges          numbers that are fresher than the nav's own `badge`, by nav item key: { requests: 3 } (refreshCounters keeps it in step)
//   app.collections     [{ key, title, count, unpublished, ... }] (counts per language; «can this person open any collection» for the dashboard)
//   app.newRequests     number of requests with the work status «Новая»
//   app.user            the signed-in user with his rights worked out by the server: { id, name, email, status, roleId, roleName, preset, builtin, perms, templates }
//                       (null until app.ready). refreshCurrentUser() re-reads the rights after roles / users were edited. can(area, action, { template }) checks
//                       app.user (reactive: use it in templates); can(user, ...) of '@/utils/permissions.js' checks any user. Areas and actions: see utils/permissions.js.
//   app.error           text when the first load failed (App.vue shows it with a «Повторить» button)
//   canAnyCol(action) / anySettings()  small helpers for the rail and the access check: is any collection / any part of Settings open to the current user
// Language helpers for templates (langName, tag, prefix, unprefix, isMain, isMulti, withLang...) are in '@/utils/locales.js'; this store keeps them in step (setLanguages).
// The SCHEMA (sections, templates, collections, settings groups...) is loaded once before the app mounts (schema/registry.js).
import { onBeforeUnmount, reactive, watch } from 'vue'
import { api } from '@/api/index.js'
import { fetchSchema, schema } from '@/schema/registry.js'
import { setHeadlineMarks } from '@/utils/html.js'
import { setCurrentLocale, setLanguages } from '@/utils/locales.js'
import { can as canUser } from '@/utils/permissions.js'
import { settingsAreas } from '@/components/settings/settingsSchema.js'
import { t } from '@/i18n/index.js'

const LANG_KEY = 'itk-admin:lang' // a UI preference of this browser, not site data
const rememberedLocale = (languages) => {
  let code = null
  try { code = localStorage.getItem(LANG_KEY) } catch (e) { /* not remembered */ }
  return code && languages.list.some((l) => l.code === code) ? code : languages.main
}

const startLanguages = { main: 'ru', list: [{ code: 'ru', name: 'Русский' }] } // i18n-ignore: the default of the site data, not a text of the interface
setLanguages(startLanguages)

export const app = reactive({
  siteInfo: api.site.currentSiteSync(),
  languages: startLanguages,
  locale: startLanguages.main,
  nav: [],
  badges: {},
  collections: [],
  newRequests: 0,
  user: null,
  ready: false,
  error: '',
})

// utils/locales.js reads the content language for defaults that depend on it (the home address of a new link); sync, so it is right before the screens re-mount
watch(() => app.locale, setCurrentLocale, { immediate: true, flush: 'sync' })

/** Switch the content language (Pages, Collections, Settings texts, Menus). Unknown codes are ignored. Returns true when the language is now `code`. */
export function setContentLocale(code) {
  if (!app.languages.list.some((l) => l.code === code)) return false
  if (app.locale !== code) app.locale = code
  try { localStorage.setItem(LANG_KEY, code) } catch (e) { /* not remembered */ }
  return true
}

/* A screen that would lose something when the content language changes registers a guard (in setup): `async (nextCode) => boolean`. `false` stops the switch.
   The record screen saves its draft and remembers the open tab; Settings asks about unsaved changes. The guard is dropped when the screen goes away. */
const localeGuards = new Set()
export function useLocaleGuard(fn) {
  localeGuards.add(fn)
  onBeforeUnmount(() => localeGuards.delete(fn))
}

/** The switch the USER makes: runs the guards of the screen on display, then switches. Returns true when the language is now `code`. */
export async function switchContentLocale(code) {
  if (code === app.locale) return true
  if (!app.languages.list.some((l) => l.code === code)) return false
  for (const guard of [...localeGuards]) {
    if ((await guard(code)) === false) return false
  }
  return setContentLocale(code)
}

/** Re-read the languages of the site. A removed current language falls back to the main one. */
export async function reloadLanguages() {
  const L = await api.site.getLanguages()
  app.languages = L
  setLanguages(L)
  if (!L.list.some((l) => l.code === app.locale)) setContentLocale(L.main)
  await refreshCounters() // the per-language numbers of app.collections follow the new list
  return L
}

/** Does the current user (app.user) have this right? can('pages', 'edit', { template: 'blog_article' }); action defaults to 'view'. */
export const can = (area, action = 'view', opts = {}) => canUser(app.user, area, action, opts)

/** Re-read the rights of the current user (after Настройки → Роли / Пользователи changed his role or the role's rights). */
export async function refreshCurrentUser() {
  app.user = await api.users.me()
  return app.user
}

/** Is any collection of the site open to the current user (the rail item «Коллекции»)? */
export const canAnyCol = (action = 'view') => app.collections.some((c) => can('col:' + c.key, action))
/** Does the current user have any right to look at Settings (the rail item «Настройки»)? */
export const anySettings = () => settingsAreas().some((a) => can(a))

/** The numbers of the rail need the right to Заявки: a role without it gets 0 and no request (the server answers 403). */
const requestCounts = (user) => (canUser(user, 'requests') ? api.requests.counts() : Promise.resolve({ new: 0 }))

/** Re-read the rail data (call after anything that changes requests or collections). The numbers are for the whole site: `unpublished` = versions in ANY language, `newRequests` = requests of every language. */
export async function refreshCounters() {
  const [cols, counts] = await Promise.all([api.collections.listCollections(), requestCounts(app.user)])
  app.collections = cols
  app.newRequests = counts.new
  app.badges = { ...app.badges, requests: counts.new }
}

/** Everything the screens need before they mount: the schema, the site's languages, the rail numbers and the signed-in user, assigned in ONE step. */
async function load() {
  const [, languages, collections, user] = await Promise.all([fetchSchema(), api.site.getLanguages(), api.collections.listCollections(), api.users.me()])
  const counts = await requestCounts(user)
  setHeadlineMarks(schema.design.headlineMarks)
  setLanguages(languages)
  Object.assign(app, { siteInfo: api.site.currentSiteSync(), languages, locale: rememberedLocale(languages), nav: schema.nav || [], badges: { requests: counts.new }, collections, newRequests: counts.new, user })
}

export async function initApp() {
  app.error = ''
  try {
    await load()
    app.ready = true
  } catch (e) {
    app.error = (e && e.message) || t('core.app_open_failed')
  }
}

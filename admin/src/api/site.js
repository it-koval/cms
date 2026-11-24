// The site this admin edits (one per install), its content languages and the data of the «Сегодня» screen.
//   currentSiteSync()                      { key, name, domain }   from window.ITK_ADMIN.site (written by the server shell)
//   getLanguages()                         GET /languages         -> { main:'ru', list:[{ code:'ru', name:'Русский' }] }  main is always first
//                                          (the server may answer a plain list of codes / { code, name } rows: both are turned into this shape)
//   getDashboard()                         GET /dashboard         -> { newRequests, failedCount, unpublished, awaiting, recent, languages, locales }  for «Сегодня»
// Content languages are configured by the site developer.
import { http, boot } from './http.js'
import { knownName } from '@/utils/locales.js'

const MAIN = 'ru'

export function currentSiteSync() {
  const s = boot().site || {}
  return { key: 'site', name: s.name || 'ITK CMS', domain: s.domain || (typeof location !== 'undefined' ? location.host : '') }
}

/** Any shape the server may use for the languages -> { main, list:[{ code, name }] }. */
export function normalizeLanguages(raw, fallbackMain = MAIN) {
  const rows = Array.isArray(raw) ? raw : raw && Array.isArray(raw.list) ? raw.list : []
  const list = rows
    .map((r) => (typeof r === 'string' ? { code: r, name: knownName(r) } : r && r.code ? { code: r.code, name: r.name || knownName(r.code) } : null))
    .filter(Boolean)
  const main = (raw && !Array.isArray(raw) && raw.main) || (list[0] && list[0].code) || fallbackMain
  if (!list.some((l) => l.code === main)) list.unshift({ code: main, name: knownName(main) })
  list.sort((a, b) => (a.code === main ? -1 : b.code === main ? 1 : 0))
  return { main, list }
}

export async function getLanguages() {
  return normalizeLanguages(await http.get('languages'))
}

export async function getDashboard() {
  const d = (await http.get('dashboard')) || {}
  return { newRequests: [], failedCount: 0, unpublished: [], awaiting: [], recent: [], ...d, languages: d.languages ? normalizeLanguages(d.languages) : { main: MAIN, list: [{ code: MAIN, name: knownName(MAIN) }] } }
}

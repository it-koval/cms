// Site settings, menu areas + menus and redirects: a thin fetch client over /admin/api.
//   get(locale)                 GET    settings?locale          shared settings + the TEXTS of that language (fields with `tr`), flat: { phone, email, ..., tgOn, tgRoutes, maxOn, maxRoutes; read-only yes / no: tgReady, tgFallback, maxFallback, mailReady, maxReady }
//   update(patch, locale)       PATCH  settings?locale          saved at once (no publish step); returns the merged settings. 422 { details:[{ path, message }] } for a bad field
//   sendTelegramTest({ form, chatId })   POST settings/telegram-test   a test message to the chat (`form` = form key or '*')
//   sendMaxTest({ form, chatId })        POST   settings/max-test        → { ok, chat, sent }; 502 { error } when MAX is not connected or refused. chatId: one MAX id or several with commas
//   MENU AREAS (declared in the site schema or added in the admin): { key, name, depth (1 flat | 2 one level of nested items), snippet, usedIn:[{key,name}], usedInAll, counts:{ ru:n } }
//   getMenuAreas()              GET    menu-areas
//   createMenuArea({name,depth})   POST   menu-areas            -> the area (the server makes the latin key from the name)
//   updateMenuArea(key,{name,depth})   PATCH  menu-areas/:key
//   removeMenuArea(key)         DELETE menu-areas/:key          -> { ok, usedIn }
//   getMenus(locale)            GET    menus?locale             { [areaKey]: [{ _id, label, link:{kind,page,url}, children? }] }, one key per area
//   saveMenus(menus, locale)    PUT    menus?locale             the items of that language
//   listRedirects({ locale })   GET    redirects?locale         ONE list of full paths: [{ id, from, to, code, locale, createdAt }]
//   createRedirect({from,to,code}) POST redirects · updateRedirect(id, patch) PATCH redirects/:id · removeRedirect(id) DELETE redirects/:id
import { http } from './http.js'

const loc = (locale) => ({ locale: locale || undefined })
const enc = encodeURIComponent

export const get = (locale) => http.get('settings', loc(locale))
export const update = (patch = {}, locale) => http.patch('settings', patch, loc(locale))
export const sendTelegramTest = ({ form = '*', chatId = '' } = {}) => http.post('settings/telegram-test', { form, chatId })

export const sendMaxTest = ({ form = '*', chatId = '' } = {}) => http.post('settings/max-test', { form, chatId })

export const getMenuAreas = () => http.get('menu-areas')
export const createMenuArea = ({ name, depth = 1 } = {}) => http.post('menu-areas', { name, depth })
export const updateMenuArea = (key, patch = {}) => http.patch(`menu-areas/${enc(key)}`, patch)
export const removeMenuArea = (key) => http.del(`menu-areas/${enc(key)}`)
export const getMenus = (locale) => http.get('menus', loc(locale))
export const saveMenus = (menus, locale) => http.put('menus', menus, loc(locale))

export const listRedirects = ({ locale } = {}) => http.get('redirects', loc(locale))
export const createRedirect = ({ from, to, code = 301 }) => http.post('redirects', { from, to, code })
export const updateRedirect = (id, patch) => http.patch(`redirects/${enc(id)}`, patch)
export const removeRedirect = (id) => http.del(`redirects/${enc(id)}`)

//   SEO OF ADDRESSES (area set:seo; saved at once): row { id, path, match: 'exact'|'prefix', locale: ?string, title, description, robots, canonical, ogImage, active, createdAt, updatedAt }
//   listSeoRules({ q, locale })       GET    seo-rules?q&locale
//   createSeoRule(body)               POST   seo-rules                 -> the row · 422 { error, details:{ path|title|robots|... : message } }
//   updateSeoRule(id, patch)          PATCH  seo-rules/:id             -> the row
//   removeSeoRule(id)                 DELETE seo-rules/:id
//   checkSeoAddress({ path, locale }) GET    seo-rules/check?path&locale
//        -> { path, answer: { kind: 'route'|'redirect'|'page'|'missing', status, label, to?, page? }, rules: [{ id, path, match, locale, winner }],
//             meta: null | { title|description|robots|canonical|ogImage: { value, source: 'rule'|'page'|'title'|'none' } } }
//   ROBOTS.TXT AND LLMS.TXT (area set:seo; saved at once): { robots|llms: { text, default, custom, file } }, text = what the site serves from the settings now (an own text or the standard one),
//   default = the standard text, custom = an own text is set, file = a file of that name lies in public/ and the site serves it instead of the text
//   getSeoFiles()                     GET    seo-files
//   saveSeoFiles({ robots?, llms? })  PUT    seo-files                 a missing key stays as it is; null or '' (or the standard text) = back to the standard text -> same answer as GET
//                                                                      · 422 { error, details:{ robots|llms: message } } (a text over 64 KB)
export const listSeoRules = ({ q, locale } = {}) => http.get('seo-rules', { q: q || undefined, ...loc(locale) })
export const createSeoRule = (body) => http.post('seo-rules', body)
export const updateSeoRule = (id, patch) => http.patch(`seo-rules/${enc(id)}`, patch)
export const removeSeoRule = (id) => http.del(`seo-rules/${enc(id)}`)
export const checkSeoAddress = ({ path, locale } = {}) => http.get('seo-rules/check', { path, ...loc(locale) })
export const getSeoFiles = () => http.get('seo-files')
export const saveSeoFiles = (patch = {}) => http.put('seo-files', patch)

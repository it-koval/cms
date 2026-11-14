// The thin fetch client to the Symfony admin API (/admin/api, JSON, session cookie + X-CSRF-Token).
// Page contract: the server shell sets window.ITK_ADMIN = { adminBase, apiBase, previewBase, csrf, user, site:{ name, domain }, loginUrl?, logoutUrl? }; every address of the admin is the server's
// (itk_cms.admin_path may put the admin anywhere: /cp, /site/panel), nothing below knows it but the dev fallbacks.
// In `npm run dev` the object is missing: the defaults below apply (`/admin`, or VITE_ITK_ADMIN_PATH of the environment of `npm run dev`), requests go through the Vite proxy, the CSRF token comes with GET /me
// (users.me() hands it to setCsrf). The bundle puts `csrf` in the answer of /me; a site on an older bundle leaves `npm run dev` read-only, and the first refused write says why (DEV_CSRF_HINT, README «Разработка»).
// A file answer (Content-Disposition: attachment) resolves with { file: true, blob, name }; query values may be arrays / objects (see addQuery).
// Errors: the server answers { error, details? } with an HTTP code (403 / 404 / 409 / 422); they become ApiError with the server's message (isConflict() tells the 409 of a stale save).

import { t } from '@/i18n/index.js'

export class ApiError extends Error {
  constructor(message, status = 400, details = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.details = details
  }
}

/**
 * A save over a draft that someone else changed meanwhile: 409 with details { code: 'conflict', current: { version, by, at, via, viaName } } (nothing was written).
 * Other 409 answers («адрес занят», «сохраняет кто-то другой») are plain errors.
 */
export const isConflict = (e) => !!e && e.status === 409 && !!e.details && e.details.code === 'conflict'

/** window.ITK_ADMIN as the server shell wrote it ({} in dev). */
export const boot = () => (typeof window !== 'undefined' && window.ITK_ADMIN) || {}
/** Where the admin stands on the server («/admin», «/cp»), without a trailing slash. */
export const adminBase = () => String(boot().adminBase || import.meta.env.VITE_ITK_ADMIN_PATH || '/admin').replace(/\/+$/, '')
const apiBase = () => String(boot().apiBase || adminBase() + '/api').replace(/\/+$/, '')
export const loginUrl = () => boot().loginUrl || adminBase() + '/login'
export const logoutUrl = () => boot().logoutUrl || adminBase() + '/logout'

/** What a refused write says in `npm run dev` when no CSRF token ever arrived (the /me of an older bundle has no `csrf`). */
export const DEV_CSRF_HINT = t('core.http_dev_csrf')

let csrf = boot().csrf || ''
/** The dev server has no shell to hand the token over: GET /me may carry it. */
export const setCsrf = (token) => { if (token) csrf = token }

const FALLBACK = {
  401: t('core.http_session_ended'),
  403: t('core.http_forbidden'),
  404: t('core.http_not_found'),
  413: t('core.http_file_too_big'),
  415: t('core.http_file_type'),
  429: t('core.http_too_many'),
}

/** A value into the query string. Empty values are skipped; an array becomes `k[]=a&k[]=b`, an object `k[sub]=…` (PHP reads both as arrays: `filter[date][from]=…`). */
function addQuery(p, k, v) {
  if (v === undefined || v === null || v === '' || v === false) return
  if (Array.isArray(v)) v.forEach((x) => addQuery(p, `${k}[]`, x))
  else if (typeof v === 'object') Object.entries(v).forEach(([kk, vv]) => addQuery(p, `${k}[${kk}]`, vv))
  else p.append(k, v === true ? '1' : String(v))
}

function url(path, query) {
  const u = apiBase() + '/' + String(path).replace(/^\/+/, '')
  const p = new URLSearchParams()
  Object.entries(query || {}).forEach(([k, v]) => addQuery(p, k, v))
  const s = p.toString()
  return s ? `${u}?${s}` : u
}

/** A file name out of Content-Disposition: attachment; filename="a.csv"; filename*=UTF-8''a.csv */
function fileNameOf(disposition) {
  const star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(disposition)
  if (star) { try { return decodeURIComponent(star[1].trim().replace(/^"|"$/g, '')) } catch (e) { /* the plain form below */ } }
  const plain = /filename="?([^";]+)"?/i.exec(disposition)
  return plain ? plain[1].trim() : 'file'
}

/** A body the browser lets a request outlive the page with: the limit of keepalive bodies is 64 KiB in total for the page, counted in bytes (a Cyrillic letter is 2), so some room is left. */
export const KEEPALIVE_MAX_BYTES = 60000
let closing = false
/**
 * Runs `send`, which must START its requests before it returns (they are built synchronously, as every http.* call is), for a tab that is closing: the writes it starts
 * outlive the page (fetch keepalive) when their body fits in KEEPALIVE_MAX_BYTES. Every other request, the autosave of an open tab included, is an ordinary one:
 * a keepalive request that is too big for the browser fails at once with a network error, whatever the server would have said.
 */
export function whileClosing(send) {
  closing = true
  try { return send() } finally { closing = false }
}

let leaving = false
function toLogin() {
  if (leaving) return
  leaving = true
  window.location.assign(loginUrl())
}

/**
 * One request. `body` is sent as JSON (a FormData as multipart). Resolves with the parsed JSON (null for an empty answer).
 * A server that answers with its login page (expired session) sends the browser to the login form.
 */
export async function request(method, path, { query, body } = {}) {
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  if (csrf && method !== 'GET') headers['X-CSRF-Token'] = csrf
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'
  const payload = body === undefined ? undefined : isForm ? body : JSON.stringify(body)
  // a small write of a closing tab (whileClosing) may outlive the page: the last autosave still reaches the server. The size is in UTF-8 bytes, not in characters
  const keepalive = closing && method !== 'GET' && typeof payload === 'string' && new TextEncoder().encode(payload).length < KEEPALIVE_MAX_BYTES
  let res
  try {
    res = await fetch(url(path, query), { method, headers, credentials: 'same-origin', body: payload, keepalive })
  } catch (e) {
    throw new ApiError(t('core.http_offline'), 0)
  }
  if (res.redirected && /\/login\b/.test(res.url)) { toLogin(); throw new ApiError(FALLBACK[401], 401) }
  // an answer that is a file (an action that streams a download): handed over as { file: true, blob, name }
  const disposition = res.headers.get('Content-Disposition') || ''
  if (res.ok && /^\s*attachment/i.test(disposition)) return { file: true, blob: await res.blob(), name: fileNameOf(disposition) }
  const text = res.status === 204 ? '' : await res.text()
  let data = null
  if (text) {
    try { data = JSON.parse(text) } catch (e) { data = null }
  }
  if (!res.ok) {
    if (res.status === 401) toLogin()
    // a role that got «двухэтапный вход обязателен» while the page was open: the shell now shows the setup
    if (res.status === 403 && data && data.details && data.details.code === '2fa_enrol') { window.location.reload(); throw new ApiError(FALLBACK[403], 403) }
    const noToken = import.meta.env.DEV && !csrf && res.status === 403 && !!data && !!data.details && data.details.code === 'csrf'
    const message = noToken ? DEV_CSRF_HINT : (data && typeof data.error === 'string' && data.error) || FALLBACK[res.status] || t('core.http_failed')
    throw new ApiError(message, res.status, (data && data.details) ?? null)
  }
  if (text && data === null) throw new ApiError(t('core.http_bad_answer'), res.status)
  return data
}

export const http = {
  get: (path, query) => request('GET', path, { query }),
  post: (path, body = {}, query) => request('POST', path, { body, query }),
  put: (path, body = {}, query) => request('PUT', path, { body, query }),
  patch: (path, body = {}, query) => request('PATCH', path, { body, query }),
  del: (path, query) => request('DELETE', path, { query }),
  upload: (path, form) => request('POST', path, { body: form }),
}

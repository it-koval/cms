// History of changes and the trash: a thin fetch client over /admin/api/history.
//   list(kind, id, { locale, scope, limit, offset })   GET  history/:kind/:id    scope: 'all' includes all languages
//        → { items: [{ id, locale, current, action, userName, title, summary, baseline, createdAt, updatedAt }], total, limit, offset, keep, days }, newest first
//   entry(id)                                   GET  history/revision/:id  the entry + `against` (the rows of what returning to it would change now), `current`, `inTrash`
//   restore(id)                                 POST history/revision/:id/restore   → { ok, kind, id, key, locale, restored: 'version' | 'trash' }
//        the draft becomes that version (the return is recorded in the history too); for a deletion the page or record comes back as a draft
//   trash({ kind, key })                        GET  history/trash?kind=page|item&key=   → { items: [{ id (of the entry), kind, resourceId, key, keyTitle, title, userName, deletedAt, locales, wasLive, canRestore, restorable }], total }
import { http } from './http.js'

const enc = encodeURIComponent

export const list = (kind, id, { locale, scope, limit, offset } = {}) => http.get(`history/${enc(kind)}/${enc(id)}`, { locale: locale || undefined, scope, limit, offset })
export const entry = (id) => http.get(`history/revision/${enc(id)}`)
export const restore = (id) => http.post(`history/revision/${enc(id)}/restore`, {})
export const trash = ({ kind, key } = {}) => http.get('history/trash', { kind: kind || undefined, key: key || undefined })

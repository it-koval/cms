// The bell of the admin: a thin fetch client over /admin/api/notifications. Every signed-in person reads his own (the server picks by his rights and role).
//   list({ unread, limit, offset })   GET  notifications?unread=1&limit=&offset=
//        → { items: [{ id, type, title, body, link, level: 'info'|'warning'|'error', payload, read, createdAt (ISO) }], total, unread }   newest first
//        `link` is a route of this admin ('/requests/12') or a full http(s) address; `total` follows the `unread` filter, `unread` is always the number of unread ones
//   markRead(ids)                     POST notifications/read { ids }   → { ok, unread }
//   markAllRead()                     POST notifications/read { all: true }   → { ok, unread }
import { http } from './http.js'

export const list = ({ unread = false, limit = 30, offset = 0 } = {}) => http.get('notifications', { unread: unread || undefined, limit, offset: offset || undefined })
export const markRead = (ids) => http.post('notifications/read', { ids })
export const markAllRead = () => http.post('notifications/read', { all: true })

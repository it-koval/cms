// Requests (заявки) API: a thin fetch client over /admin/api.
//   list({ status, form, period, q, from, to, locale })   GET   requests      locale: only requests that came from that language version ('' = all)
//   get(id)                                       GET   requests/:id
//   update(id, { work, comment })                 PATCH requests/:id          work: 'new' | 'progress' | 'closed' | 'spam'; comment: private note
//   resend(id)                                    POST  requests/:id/resend   sends to Telegram again, answers the request with its new delivery state
//   counts()                                      GET   requests/counts       { total, new, failed }   new = work status «Новая»
//   forms()                                       the site's forms [{ key, title }] (schema.forms) for the «Форма» filter; `form` of list() is the key
// A request: { id, dt (ISO), locale, form, phone, name, city, place (= the form's title), note (what the visitor wrote), st: 'delivered'|'failed' (Telegram delivery),
//              work: 'new'|'progress'|'closed'|'spam' (set by the staff), comment (private note), log:[[kind:'ok'|'fail', text, ISO time of the event, UTC]] }.
// status filter of list(): a work status, or 'failed' (not delivered to Telegram). period: '' | 'today' | '7' | '30' | 'custom' (with from/to as 'YYYY-MM-DD').
import { http } from './http.js'
import { schema } from '@/schema/registry.js'

export const list = ({ status = '', form = '', period = '', q = '', from = '', to = '', locale = '' } = {}) => http.get('requests', { status, form, period, q, from, to, locale })
export const get = (id) => http.get(`requests/${encodeURIComponent(id)}`)
export const update = (id, { work, comment } = {}) => http.patch(`requests/${encodeURIComponent(id)}`, { work, comment })
export const resend = (id) => http.post(`requests/${encodeURIComponent(id)}/resend`)
export const counts = () => http.get('requests/counts')
export async function forms() {
  return schema.forms.map((f) => ({ key: f.key, title: f.title }))
}

// Requests (заявки) API: a thin fetch client over /admin/api.
//   list({ status, form, period, q, from, to, locale })   GET   requests      locale: only requests that came from that language version ('' = all)
//   get(id)                                       GET   requests/:id
//   update(id, { work, comment })                 PATCH requests/:id          work: 'new' | 'progress' | 'closed' | 'spam'; comment: private note
//   resend(id, { channel }?)                      POST  requests/:id/resend   sends again: to `channel` ('telegram' | 'max'), without it to every channel that failed (none failed: every active one). Answers the request with its new delivery state; HTTP 202 + queuedChannels for a delivery queued after formatting more than 20 parts
//   counts()                                      GET   requests/counts       { total, new, failed }   new = work status «Новая»
//   forms()                                       the site's forms [{ key, title }] (schema.forms) for the «Форма» filter; `form` of list() is the key
// A request: { id, dt (ISO), locale, form, phone, name, city, place (= the form's title), note (what the visitor wrote), data (submitted values keyed by the form field), st: 'delivered'|'failed' (the aggregate of the channels),
//              delivery: 'sent'|'failed'|'pending' (the aggregate), deliveryError (the errors of the failed channels, one per line),
//              channels: [{ channel: 'telegram'|'max', state: 'pending'|'sent'|'failed', error }] (the channels that are on for the site or already have a state on this request),
//              work: 'new'|'progress'|'closed'|'spam' (set by the staff), comment (private note), log:[[kind:'ok'|'fail', text, ISO time of the event, UTC]] }.
// status filter of list(): a work status, or 'failed' (not delivered to some messenger). period: '' | 'today' | '7' | '30' | 'custom' (with from/to as 'YYYY-MM-DD').
import { http } from './http.js'
import { schema } from '@/schema/registry.js'

export const list = ({ status = '', form = '', period = '', q = '', from = '', to = '', locale = '' } = {}) => http.get('requests', { status, form, period, q, from, to, locale })
export const get = (id) => http.get(`requests/${encodeURIComponent(id)}`)
export const update = (id, { work, comment } = {}) => http.patch(`requests/${encodeURIComponent(id)}`, { work, comment })
export const resend = (id, body) => http.post(`requests/${encodeURIComponent(id)}/resend`, body)
export const counts = () => http.get('requests/counts')
export async function forms() {
  return schema.forms.map((f) => ({ key: f.key, title: f.title }))
}

// «Состояние сайта» (Разработчику): a thin fetch client over /admin/api/health. Needs the right `dev` (смотреть; действия — править).
//   report()                 GET  health                          → { status 'ok'|'warn'|'fail', generatedAt,
//        messenger: { queues: [{ name, waiting (null when the transport does not work), error? }], failed: { available, total, items: [{ id, type, label, error, errorClass, retries, failedAt, queue }], error? } },
//        requests: { failed, items: [{ id, dt, form, error }] },  schedules: { available, items: [{ schedule, trigger, next }] },
//        disk: [{ key, label, free, total, usedPct, status }],    versions: { php, symfony, itk, itkReference },
//        updates: { enabled, current, latest, hasUpdate, checkedAt, message },  checks: [{ name, status, message }] }
//   retry(id) / remove(id)   POST health/messenger/:id/retry|delete  a failed message goes back to its queue / is dropped   → { ok }
//   resendRequest(id)        POST health/requests/:id/resend        every channel that failed gets the request again → { ok, delivery: 'sent'|'failed'|'pending', error, channels, queuedChannels }; HTTP 202 when queued
//   refreshUpdates()         POST health/updates/refresh            → { ok, updates }
//   clearPageCache()         POST health/page-cache/clear           → { ok }  every page kept by the page cache is forgotten (500 { error } when the cache refused)
import { http } from './http.js'

const enc = encodeURIComponent

export const report = () => http.get('health')
export const retry = (id) => http.post(`health/messenger/${enc(id)}/retry`, {})
export const remove = (id) => http.post(`health/messenger/${enc(id)}/delete`, {})
export const resendRequest = (id) => http.post(`health/requests/${enc(id)}/resend`, {})
export const refreshUpdates = () => http.post('health/updates/refresh', {})
export const clearPageCache = () => http.post('health/page-cache/clear', {})

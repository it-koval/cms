// Settings → Почта (area set:mail). The password never comes back: only `passwordSet`.
//   get()          GET  settings/mail       { enabled, host, port, encryption: 'none'|'ssl'|'starttls', username, passwordSet, fromAddress, fromName,
//                                             configured, source: 'settings'|'env'|'none', envFallback, problem?, last: { okAt, error, errorAt, kind } }
//   save(patch)    PUT  settings/mail       saved at once; `password` empty or missing keeps the saved one, `clearPassword: true` removes it · 422 { details: { field: message } }
//   sendTest(to?)  POST settings/mail-test  a test letter NOW to `to` (default: the person at the screen) → { ok, to } · 422 not configured · 502 { error } with the reason of the mail server
import { http } from './http.js'

export const get = () => http.get('settings/mail')
export const save = (patch) => http.put('settings/mail', patch)
export const sendTest = (to) => http.post('settings/mail-test', { to: to || undefined })

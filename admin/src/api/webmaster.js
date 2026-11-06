// «Яндекс Вебмастер» on «Сегодня»: a thin fetch client over /admin/api/yandex-webmaster. The right `set:analytics` (смотреть; подключить и отключить — править).
//   status()         GET    yandex-webmaster          -> the status body (below); 403 when the role has no right: the card is not drawn. The server refreshes from Yandex when its snapshot is over 6 hours old
//   connect(token)   POST   yandex-webmaster/connect  { token }  -> the status body; 422 { error } (empty / rejected token, no public site address), 502 { error } (Yandex does not answer)
//   refresh()        POST   yandex-webmaster/refresh  -> the status body
//   disconnect()     DELETE yandex-webmaster          -> the status body (state 'not_connected')
// Status body: { state: 'not_connected'|'no_address'|'no_http_client'|'token_invalid'|'verifying'|'verification_failed'|'ready', canManage, tokenLink|null, connectedAt|null, tokenExpiresAt|null, tokenExpiresSoon,
//   host:{ id, url }|null, webmasterUrl|null, verification:{ state, failReason|null }|null, summary:{ sqi, searchable, excluded, problems:{ FATAL, CRITICAL, POSSIBLE_PROBLEM, RECOMMENDATION } }|null,
//   queries:{ from, to, rows:[{ text, shows|null, clicks|null }] }|null, updatedAt|null, error|null }
import { http } from './http.js'

export const status = () => http.get('yandex-webmaster')
export const connect = (token) => http.post('yandex-webmaster/connect', { token })
export const refresh = () => http.post('yandex-webmaster/refresh', {})
export const disconnect = () => http.del('yandex-webmaster')

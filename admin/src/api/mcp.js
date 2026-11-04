// ИИ-ассистенты (MCP). Two sets of calls over /admin/api:
// Настройки → ИИ-ассистенты (area set:mcp, administrators):
//   get()                GET  mcp                      → { enabled, endpoint, stdio, scopes: [{ key, label, hint, default, warning? }], tools: [{ name, scope, readOnly }],
//                                                         tokens: [{ id, name, hint, scopes, state: 'active'|'expired'|'revoked', createdAt, lastUsedAt, expiresAt, revokedAt, owner: { id, name, email, blocked } }] }
//   setEnabled(on)       PUT  mcp { enabled }          the same answer; the switch is off by default
//   revoke(id)           POST mcp/tokens/:id/revoke    the same answer; an administrator stops anybody's key
// Мой профиль → Мои токены для ИИ-ассистентов (only the person's own keys; 403 while MCP is switched off):
//   mine()               GET  me/tokens                → { endpoint, scopes: [{ key, label, hint, default, warning?, available }], expires: [30, 90, 365, null], tokens: [as above, without owner] }
//   create({ name, scopes, expires })   POST me/tokens → 201 { token, plain }   `plain` (itk_…) is shown ONCE; 422 { details: { name|scopes|expires } }, 409 over 20 active keys, 429 over 10 new keys an hour
//   revokeMine(id)       POST me/tokens/:id/revoke     → the key as in the list
import { http } from './http.js'

const enc = encodeURIComponent
export const get = () => http.get('mcp')
export const setEnabled = (enabled) => http.put('mcp', { enabled })
export const revoke = (id) => http.post(`mcp/tokens/${enc(id)}/revoke`)
export const mine = () => http.get('me/tokens')
export const create = (body) => http.post('me/tokens', body)
export const revokeMine = (id) => http.post(`me/tokens/${enc(id)}/revoke`)

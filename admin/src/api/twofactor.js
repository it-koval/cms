// Двухэтапный вход (TOTP) того, кто сидит за экраном: a thin fetch client over /admin/api/2fa. Everything is about the signed-in person only.
//   status()                GET  2fa                  → { enabled, pending, required, recoveryLeft, enabledAt }   `required`: his role demands the factor
//   setup()                 POST 2fa/setup            → { secret, uri }   a new pending secret; draw the QR from `uri`; 409 when the factor is already on
//   enable(code)            POST 2fa/enable           → { ok, recoveryCodes: [10 strings] }   confirms with the first code; the codes are shown once; 422 on a wrong code
//   recoveryCodes(password) POST 2fa/recovery-codes   → { recoveryCodes }   new codes instead of the old ones; 422 on a wrong password
//   disable(password)       POST 2fa/disable          → { ok }   409 when the role demands the factor
// Five wrong answers in a row lock the factor for 15 minutes (429).
import { http } from './http.js'

export const status = () => http.get('2fa')
export const setup = () => http.post('2fa/setup')
export const enable = (code) => http.post('2fa/enable', { code })
export const recoveryCodes = (password) => http.post('2fa/recovery-codes', { password })
export const disable = (password) => http.post('2fa/disable', { password })

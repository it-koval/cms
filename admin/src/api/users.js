// Users and roles: a thin fetch client over /admin/api. The server works out the rights of every role and checks them (nobody hands out more than he has).
//   me()                            GET    me                       -> the signed-in user with his effective rights { id, name, email, status, roleId, roleName, preset?, builtin?, perms, templates, csrf? }   `csrf`: the token of the admin API, goes to setCsrf (`npm run dev` has no shell page to take it from; an older bundle does not send it)
//   list()                          GET    users                    -> [{ id, name, email, roleId, roleName, status, lastSignIn, createdAt }]
//   invite({ name, email, roleId }) POST   users                    -> the user + `emailed: true` (a link went to his mail, no password) or `password` (one-time, show it once) + `emailError?`; 422 on a wrong name / e-mail, 409 when the e-mail is taken
//   update(id, { name, email, roleId })   PATCH /users/:id          409 when the only active «Администратор» would lose the role; 403 for your own role
//   resetPassword(id)               POST   users/:id/reset-password -> { ok, email, invited, emailed, password?, emailError? }   `emailed`: a link went to the person (his old password works until he sets a new one); else `password` is a new one-time password for ANOTHER person: show it to the admin once; 403 for yourself
//   mailReady()                     GET    mail-ready               -> { ready }   can the site send mail (then the two calls above send a link)
//   changePassword({ current, password, repeat })   POST me/password   -> { ok }   the person at the screen changes his own password; 422 { details: { current | password | repeat } }, 429 after five wrong tries in 15 minutes
//   setBlocked(id, blocked)         POST   users/:id/{block|unblock}   409 for yourself and for the only active «Администратор»
//   remove(id)                      DELETE users/:id                409 for yourself and for the only active «Администратор»
//   resetTwoFactor(id)              POST   users/:id/reset-2fa      → user; takes his second sign-in factor away (a lost phone); 403 for yourself. The rows of list() carry `twoFactor` (bool)
//   listRoles()                     GET    roles                    -> [{ id, name, description, builtin, preset?, perms, templates, users }]
//   createRole({ name, description?, copyOf? })   POST roles        copyOf = id of a role whose rights the new one starts with
//   updateRole(id, { name, description, perms, templates, require2fa })   PATCH roles/:id   403 for a built-in role (only { require2fa } is allowed there), 409 on a name that is taken, 422 on empty `templates`
//   removeRole(id, { moveTo? })     DELETE roles/:id?moveTo=        403 for a built-in role; 409 {details:{users}} when people have it and `moveTo` is missing
import { ApiError, http, setCsrf } from './http.js'
import { t } from '@/i18n/index.js'

const enc = encodeURIComponent
/** The built-in roles of the CMS (keys): they cannot be edited and carry a `preset` of the same name. */
const BUILTIN = ['admin', 'editor', 'author', 'requests']
const role = (r) => {
  const id = r.id ?? r.key // the server may call the id of a role its key
  return { builtin: BUILTIN.includes(id), preset: BUILTIN.includes(id) ? id : null, description: '', perms: {}, templates: null, users: 0, ...r, id }
}

export async function me() {
  const u = await http.get('me')
  if (u && u.csrf) setCsrf(u.csrf)
  if (!u) throw new ApiError(t('core.api_who_are_you'), 401)
  const builtin = BUILTIN.includes(u.roleId)
  return { roleName: t('core.role_none'), preset: builtin ? u.roleId : null, builtin, perms: {}, templates: null, ...u }
}
export const list = () => http.get('users')
export const invite = ({ name, email, roleId }) => http.post('users', { name, email, roleId })
export const update = (id, patch) => http.patch(`users/${enc(id)}`, patch)
export const resetPassword = (id) => http.post(`users/${enc(id)}/reset-password`)
export const mailReady = () => http.get('mail-ready')
export const changePassword = ({ current, password, repeat }) => http.post('me/password', { current, password, repeat })
export const setBlocked = (id, blocked) => http.post(`users/${enc(id)}/${blocked ? 'block' : 'unblock'}`)
export const remove = (id) => http.del(`users/${enc(id)}`)
export const resetTwoFactor = (id) => http.post(`users/${enc(id)}/reset-2fa`)

export const listRoles = async () => (await http.get('roles')).map(role)
export const createRole = async ({ name, description = '', copyOf = null }) => role(await http.post('roles', { name, description, copyOf }))
export const updateRole = async (id, patch) => role(await http.patch(`roles/${enc(id)}`, patch))
export const removeRole = (id, { moveTo = null } = {}) => http.del(`roles/${enc(id)}`, { moveTo })

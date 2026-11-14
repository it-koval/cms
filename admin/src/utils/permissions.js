// Permissions of the admin. The server GENERATES the areas from the structure of the site, never listed by hand per site:
// one area per Страницы, per collection of the site, Заявки, Медиатека, per Settings group, and Шаблоны (view only).
// A new collection or Settings group appears here (and in the matrix of every role) by itself.
//
//   permissionAreas()                      -> [{ key, group, label, hint?, actions:['view'|'edit'|'publish'|'delete'], scope?:'templates' }]
//   can(user, area, action, { template })  -> boolean; `user` is the object api.users.me() hands out ({ perms, templates, ... }); `template` is the
//                                             template key of a page (pages only: the role's template scope is checked too)
//   area keys: 'pages' · 'col:<collection key>' · 'requests' · 'media' · 'set:<tab>' (a settings group of the schema, menus, redirects, notifications, languages, users)
//              · 'templates'. «Пользователи» and «Роли» are ONE area: 'set:users'.
//   role (as the server hands it out)  { id, name, description, builtin, preset?, perms: { [areaKey]: ['view', ...] }, templates }: the rights are already worked out;
//                   `templates` is null (every template) or [template keys] (pages only). Built-in roles cannot be edited.
// Dependencies of one row: «Публиковать» and «Удалять» need «Править», «Править» needs «Смотреть» (closeActions / toggleAction).
import { schema } from '@/schema/registry.js'
import { templatesFor } from '@/schema/templates.js'
import { t } from '@/i18n/index.js'

export const ACTIONS = [
  { key: 'view', label: t('core.perm_view') },
  { key: 'edit', label: t('core.perm_edit') },
  { key: 'publish', label: t('core.perm_publish') },
  { key: 'delete', label: t('core.perm_delete') },
]
const ORDER = ACTIONS.map((a) => a.key)
// The columns of the role matrix: «Публиковать» first (the right an owner looks for first), then the rest in their usual order.
export const MATRIX_COLUMNS = ['publish', 'view', 'edit', 'delete'].map((key) => ACTIONS.find((a) => a.key === key))
const ALL = ['view', 'edit', 'publish', 'delete']

export const GROUPS = [
  { key: 'content', label: t('core.perm_group_content') },
  { key: 'data', label: t('core.perm_group_data') },
  { key: 'actions', label: t('core.perm_group_actions') }, // «запускать действие X»: areas action:<collection>:<action> of the actions that ask for a permission line
  { key: 'settings', label: t('core.perm_group_settings') },
  { key: 'dev', label: t('core.perm_group_dev') }, // «Состояние сайта»: the area `dev`
]

/** Every area of the site's permission matrix, in the order of the matrix. The server generates them from the structure of the site (GET schema: permissionAreas). */
export const permissionAreas = () => schema.permissionAreas

/** Add what a set of actions needs (Публиковать / Удалять -> Править -> Смотреть), in the order of the matrix columns. */
export function closeActions(list) {
  const s = new Set(list)
  if (s.has('publish') || s.has('delete')) s.add('edit')
  if (s.has('edit')) s.add('view')
  return ORDER.filter((a) => s.has(a))
}

/** The actions of one row after a box was ticked / cleared: ticking pulls in what it needs, clearing takes away what needs it. */
export function toggleAction(have, action, on, allowed = ALL) {
  const s = new Set(have)
  if (on) s.add(action)
  else {
    s.delete(action)
    if (action === 'view') ['edit', 'publish', 'delete'].forEach((a) => s.delete(a))
    if (action === 'edit') ['publish', 'delete'].forEach((a) => s.delete(a))
  }
  return closeActions([...s]).filter((a) => allowed.includes(a))
}

/** Templates an «Автор» writes by default: the article-like ones of the site (a card with a date: «Статья блога»...). */
export const articleTemplates = () => templatesFor().filter((tpl) => tpl.card && tpl.card.date).map((tpl) => tpl.key)

/** Does the user have this right? `user` = api.users.me() (perms + templates). With { template } a pages right is also limited by the role's template scope. */
export function can(user, area, action = 'view', opts = {}) {
  if (!user || !user.perms) return false
  const have = user.perms[area]
  if (!have || !have.includes(action)) return false
  if (area === 'pages' && opts.template && Array.isArray(user.templates) && !user.templates.includes(opts.template)) return false
  return true
}

/** Does `holder` (api.users.me() or a resolved role) have every right of `role` (a resolved role), incl. the template scope of Страницы?
 *  Nobody assigns a role, or changes a person, that is bigger than his own. */
export function covers(holder, role) {
  if (!holder || !role) return false
  const mine = holder.perms || {}
  const theirs = role.perms || {}
  if (!Object.keys(theirs).every((k) => theirs[k].every((a) => (mine[k] || []).includes(a)))) return false
  if (!theirs.pages || holder.templates == null) return true // no pages, or the holder has every template
  return Array.isArray(role.templates) && role.templates.every((k) => holder.templates.includes(k))
}
export const ROLE_TOO_BIG = t('core.perm_role_too_big')
export const USER_TOO_BIG = t('core.perm_user_too_big')

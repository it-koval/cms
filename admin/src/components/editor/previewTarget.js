import { can } from '@/stores/app.js'
import { getCollectionConfig } from '@/schema/collections.js'
import { getSettingsFields } from '@/schema/settings.js'
import { groupOfKey } from '@/components/settings/settingsSchema.js'

/** Accept only fields present in this viewer's form schema, including paths inside repeaters. */
export function previewField(fields, path) {
  if (typeof path !== 'string' || !path || path.length > 500) return null
  const field = fields.find((f) => f.form !== false && (path === f.k || path.startsWith(f.k + '.')))
  if (!field) return null
  const rest = path.slice(field.k.length)
  if (!rest) return field
  if (field.t !== 'repeater') return null
  const row = /^\.(\d+)\.(.+)$/.exec(rest)
  return row && previewField(field.sub || [], row[2]) ? field : null
}

/** A same-origin preview supplies an owner and field, never an arbitrary URL. */
export function previewDestination({ sec, path, rows }, locale) {
  const query = { lang: locale, field: path }
  if (Array.isArray(rows)) query.rows = JSON.stringify(rows)
  if (sec === '@settings') {
    const field = previewField(getSettingsFields(), path)
    if (!field || !can('set:' + groupOfKey(field.k), 'view')) return null
    return { name: 'settings', params: { tab: 'general' }, query }
  }
  const owner = typeof sec === 'string' && /^@collection\/([A-Za-z0-9][A-Za-z0-9_-]*)\/([^/]+)$/.exec(sec)
  if (!owner || !can('col:' + owner[1], 'view')) return null
  const cfg = getCollectionConfig(owner[1])
  if (!cfg || !previewField(cfg.tabs.flatMap((tab) => tab.fields), path)) return null
  return { name: 'collection-item', params: { col: owner[1], id: owner[2] }, query }
}

/** Translate published repeater positions to the same rows in the current draft. Never substitute a neighbour for a deleted row. */
export function resolvePreviewPath(fields, data, path, identities) {
  if (!previewField(fields, path)) return null
  if (identities === undefined) return path // older/custom templates without row metadata
  let ids
  try { ids = typeof identities === 'string' ? JSON.parse(identities) : identities } catch { return null }
  if (!Array.isArray(ids) || ids.some((id) => typeof id !== 'string' || !id)) return null
  const output = []
  let rest = path
  let at = data
  let row = 0
  while (rest) {
    const field = fields.find((f) => rest === f.k || rest.startsWith(f.k + '.'))
    if (!field) return null
    output.push(field.k)
    rest = rest.slice(field.k.length)
    at = at?.[field.k]
    if (!rest) break
    const match = field.t === 'repeater' && /^\.(\d+)\.(.+)$/.exec(rest)
    if (!match || !Array.isArray(at) || row >= ids.length) return null
    const id = ids[row++]
    const index = at.findIndex((item) => item?._id === id)
    if (index < 0) return null
    output.push(String(index))
    at = at[index]
    rest = match[2]
    fields = field.sub || []
  }
  return row === ids.length ? output.join('.') : null
}

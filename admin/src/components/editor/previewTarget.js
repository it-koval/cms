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
export function previewDestination({ sec, path }, locale) {
  const query = { lang: locale, field: path }
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

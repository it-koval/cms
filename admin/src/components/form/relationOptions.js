// Translated relation choices, cached separately for every collection and content language.
// Used by the form field (fields/RelationField.vue) and by the relation filter of a list (collections/list/ListFilters.vue). A collection the person may not open or that does not exist gives [].
import { reactive } from 'vue'
import { api } from '@/api/index.js'
import { app } from '@/stores/app.js'
import { getCollectionConfig } from '@/schema/collections.js'
import { setRelationTitle } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'

const cache = reactive({})
const pending = {}

/** Translated choices plus any existing untranslated or deleted selections, which can be removed. */
export function relationOptions(to, locale = app.locale, selected = []) {
  if (!to) return []
  const key = JSON.stringify([to, locale])
  if (!(key in cache) && !pending[key]) {
    pending[key] = api.collections.list(to, { locale, onlyTranslated: false }).then((rows) => {
      const cfg = getCollectionConfig(to)
      cache[key] = rows.map((r) => ({ id: String(r.id), title: String((cfg && r[cfg.titleField]) || '').trim() || t('forms.untitled'), translated: r.translated !== false }))
    }).catch(() => { cache[key] = [] }).finally(() => { delete pending[key] })
  }
  if (!(key in cache)) return []
  const ids = selected.map(String)
  const rows = cache[key]
  return [
    ...rows.filter((r) => r.translated || ids.includes(r.id)).map((r) => r.translated ? [r.id, r.title] : [r.id, t('forms.relation_untranslated', { title: r.title }), { unavailable: true }]),
    ...ids.filter((id) => !rows.some((r) => r.id === id)).map((id) => [id, t('forms.relation_gone'), { unavailable: true }]),
  ]
}
export const relationLoaded = (to, locale = app.locale) => JSON.stringify([to, locale]) in cache

// The title of one record for the title of a repeater row («{bike} · {yearFrom}»): utils/fields.js asks for it by collection and id; '' until the records have arrived (the row then updates on its own, the cache is reactive)
setRelationTitle((to, id) => {
  relationOptions(to)
  return cache[JSON.stringify([to, app.locale])]?.find((r) => r.id === String(id))?.title || ''
})

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

/** The options of collection `to` (reactive: empty until they arrive). */
export function relationOptions(to, locale = app.locale) {
  if (!to) return []
  const key = JSON.stringify([to, locale])
  if (!(key in cache) && !pending[key]) {
    pending[key] = api.collections.list(to, { locale, onlyTranslated: true }).then((rows) => {
      const cfg = getCollectionConfig(to)
      cache[key] = rows.map((r) => [String(r.id), String((cfg && r[cfg.titleField]) || '').trim() || t('forms.untitled')])
    }).catch(() => { cache[key] = [] }).finally(() => { delete pending[key] })
  }
  return cache[key] || []
}
export const relationLoaded = (to, locale = app.locale) => JSON.stringify([to, locale]) in cache

// The title of one record for the title of a repeater row («{bike} · {yearFrom}»): utils/fields.js asks for it by collection and id; '' until the records have arrived (the row then updates on its own, the cache is reactive)
setRelationTitle((to, id) => (relationOptions(to).find((o) => o[0] === id) || [])[1] || '')

// The records another collection offers to a relation field (a field of type 'relation' with `to`: the collection key): [[id, title], ...], loaded once per collection and kept for the session.
// Used by the form field (fields/RelationField.vue) and by the relation filter of a list (collections/list/ListFilters.vue). A collection the person may not open or that does not exist gives [].
import { reactive } from 'vue'
import { api } from '@/api/index.js'
import { getCollectionConfig } from '@/schema/collections.js'
import { setRelationTitle } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'

const cache = reactive({})
const pending = {}

/** The options of collection `to` (reactive: empty until they arrive). */
export function relationOptions(to) {
  if (!to) return []
  if (!(to in cache) && !pending[to]) {
    pending[to] = api.collections.list(to).then((rows) => {
      const cfg = getCollectionConfig(to)
      cache[to] = rows.map((r) => [String(r.id), String((cfg && r[cfg.titleField]) || '').trim() || t('forms.untitled')])
    }).catch(() => { cache[to] = [] })
  }
  return cache[to] || []
}
export const relationLoaded = (to) => to in cache

// The title of one record for the title of a repeater row («{bike} · {yearFrom}»): utils/fields.js asks for it by collection and id; '' until the records have arrived (the row then updates on its own, the cache is reactive)
setRelationTitle((to, id) => (relationOptions(to).find((o) => o[0] === id) || [])[1] || '')

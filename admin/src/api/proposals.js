// AI-agent proposals: edits an agent suggested that reach the site only after a human accepts them.
// A later feature (ARCHITECTURE.md): the server has no proposals yet and the schema says `features.ai: false`, so the screens do not offer them.
//   list({ locale })  -> []   (nothing is asked from the server while features.ai is off; with it on: GET proposals?locale)
//   accept(id) / reject(id)   POST proposals/:id/{accept|reject}
// A proposal (as listed): { id, kind:'page'|'collection', locale, key, label, old, nw, note, createdAt, pageId?, template?, sectionId?, collection?, itemId?, targetTitle, to, stale }.
import { http } from './http.js'
import { schema } from '@/schema/registry.js'

export async function list({ locale } = {}) {
  if (!schema.features.ai) return []
  return http.get('proposals', { locale: locale || undefined })
}
export const accept = (id) => http.post(`proposals/${encodeURIComponent(id)}/accept`)
export const reject = (id) => http.post(`proposals/${encodeURIComponent(id)}/reject`)

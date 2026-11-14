// Actions of a collection (Action::make(...) in the resource class): a thin fetch client.
//   run(resource, action, { ids, input, locale })   POST resources/:resource/actions/:action   { ids?, input?, locale? }
//        → { ok, message?, reload?, redirect?, download? }, or { file: true, blob, name } when the action streams a file itself.
//        A refusal of the action is an ApiError (422) with its message.
import { http } from './http.js'

export const run = (resource, action, { ids = [], input = {}, locale } = {}) =>
  http.post(`resources/${encodeURIComponent(resource)}/actions/${encodeURIComponent(action)}`, { ids, input, locale: locale || undefined })

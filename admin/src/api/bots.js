// Only credential metadata is returned. Empty tokens keep saved values; clear removes an admin override.
import { http } from './http.js'

export const get = () => http.get('settings/bots')
export const save = (patch) => http.put('settings/bots', patch)

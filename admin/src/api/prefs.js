// Settings of the signed-in person that follow him between devices (the filters, the sort and the view of a list): the server keeps them per user.
//   get(key)          GET    prefs/:key    → the saved value, or null (nothing saved; also when the server cannot answer: a preference is never worth an error)
//   put(key, value)   PUT    prefs/:key    { value }   up to 16 KB of JSON
import { http } from './http.js'

const at = (key) => `prefs/${encodeURIComponent(key)}`

export const get = async (key) => {
  try {
    const r = await http.get(at(key))
    return r ? r.value : null
  } catch (e) {
    return null
  }
}
export const put = (key, value) => http.put(at(key), { value })

// The look of the admin (colours and corners; area set:theme). Every call answers the same:
//   { theme: { brand, accent, radius, logo }, custom, defaults: { brand, accent, radius }, vars: { '--cy': '#0B5D80', … }, checks: { brand, accent }, ladder: { brand: { 50: '#…', … }, accent } }
//   checks.<colour> = { ink, ratio, level: 'AAA'|'AA'|'fail', ok, white, dark, text, textAdjusted }: can text be read on the colour (WCAG AA = 4.5)
//   get()           GET    admin-theme
//   preview(look)   POST   admin-theme/preview { brand?, accent?, radius? }   what the look would be; nothing is saved
//   save(look)      PUT    admin-theme                                          saved at once for everybody · 422 { details: { brand|accent|radius: message } }
//   reset()         DELETE admin-theme                                          back to the developer's look
import { http } from './http.js'

export const get = () => http.get('admin-theme')
export const preview = (look) => http.post('admin-theme/preview', look)
export const save = (look) => http.put('admin-theme', look)
export const reset = () => http.del('admin-theme')

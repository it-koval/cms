// Small object helpers shared by api, schema, form and preview code.
export const clone = (o) => (o === undefined ? undefined : JSON.parse(JSON.stringify(o)))
export const same = (a, b) => JSON.stringify(a) === JSON.stringify(b)

/** getPath(obj, 'points.2.title') */
export const getPath = (o, p) => String(p).split('.').reduce((a, k) => (a == null ? a : a[k]), o)

/** Mutating setter: setPath(obj, 'a.0.b', v). */
export function setPath(o, p, v) {
  const ks = String(p).split('.')
  let a = o
  for (let i = 0; i < ks.length - 1; i++) {
    if (a[ks[i]] == null) a[ks[i]] = /^\d+$/.test(ks[i + 1]) ? [] : {}
    a = a[ks[i]]
  }
  a[ks[ks.length - 1]] = v
  return o
}

/** Immutable setter: returns a deep copy of `o` with `p` set to `v`. */
export const setIn = (o, p, v) => setPath(clone(o), p, v)

export const uniq = (arr) => [...new Set(arr)]

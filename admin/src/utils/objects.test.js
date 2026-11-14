import { describe, expect, it } from 'vitest'
import { clone, getPath, same, setIn, setPath, uniq } from './objects.js'

describe('object helpers', () => {
  it('reads and writes dotted paths', () => {
    const o = { points: [{ title: 'a' }] }
    expect(getPath(o, 'points.0.title')).toBe('a')
    expect(getPath(o, 'points.5.title')).toBeUndefined()
    setPath(o, 'seo.title', 'x')
    expect(o.seo).toEqual({ title: 'x' })
    setPath(o, 'list.1', 'y')
    expect(o.list).toEqual([undefined, 'y'])
  })

  it('setIn does not touch the source', () => {
    const src = { a: { b: 1 } }
    const out = setIn(src, 'a.b', 2)
    expect(src.a.b).toBe(1)
    expect(out.a.b).toBe(2)
  })

  it('clone / same / uniq', () => {
    expect(clone(undefined)).toBeUndefined()
    expect(same({ a: [1] }, clone({ a: [1] }))).toBe(true)
    expect(uniq([1, 1, 2])).toEqual([1, 2])
  })
})

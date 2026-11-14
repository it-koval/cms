import { describe, expect, it } from 'vitest'
import { canStep, drop, find, groupsOf, height, move, nest, payload, rows, step, within } from './treeOps.js'

// a
// ├─ b
// │  └─ c
// └─ d
// e
const tree = () => [
  { id: 'a', children: [{ id: 'b', children: [{ id: 'c' }] }, { id: 'd' }] },
  { id: 'e' },
]
const ids = (nodes) => nodes.map((n) => [n.id, ...ids(n.children || [])])

describe('find / height / within / drop', () => {
  it('finds a node with its parent, index and level', () => {
    const hit = find(tree(), 'c')
    expect(hit.parentId).toBe('b')
    expect(hit.index).toBe(0)
    expect(hit.level).toBe(3)
    expect(find(tree(), 'zzz')).toBeNull()
  })

  it('compares ids as strings', () => {
    expect(find([{ id: 7 }], '7').node.id).toBe(7)
  })

  it('counts the levels a node and its branch take', () => {
    expect(height({ id: 'x' })).toBe(1)
    expect(height(tree()[0])).toBe(3)
  })

  it('tells whether a node lies inside the branch of another', () => {
    expect(within(tree(), 'c', 'a')).toBe(true)
    expect(within(tree(), 'a', 'a')).toBe(true)
    expect(within(tree(), 'e', 'a')).toBe(false)
  })

  it('drops a node together with its branch, without changing the source', () => {
    const src = tree()
    expect(ids(drop(src, 'b'))).toEqual([['a', ['d']], ['e']])
    expect(find(src, 'c')).not.toBeNull()
  })
})

describe('move', () => {
  it('moves a node to another parent at an index', () => {
    const r = move(tree(), 'e', 'a', 0)
    expect(r.ok).toBe(true)
    expect(r.from).toEqual({ parentId: null, index: 1 })
    expect(r.to).toEqual({ parentId: 'a', index: 0 })
    expect(ids(r.nodes)).toEqual([['a', ['e'], ['b', ['c']], ['d']]])
  })

  it('refuses to put a node into itself or into its own branch', () => {
    expect(move(tree(), 'a', 'a', 0).ok).toBe(false)
    expect(move(tree(), 'a', 'c', 0).reason).toMatch(/своей же ветки/)
  })

  it('keeps the depth limit, the branch counts with the node', () => {
    const r = move(tree(), 'a', null, 0, { maxDepth: 2 })
    expect(r.ok).toBe(false)
    expect(move([{ id: 'x' }, { id: 'y' }], 'x', 'y', 0, { maxDepth: 1 }).reason).toMatch(/нет вложенности/)
  })

  it('answers "same" when nothing moves', () => {
    const r = move(tree(), 'e', null, 1)
    expect(r.ok).toBe(true)
    expect(r.same).toBe(true)
  })

  it('asks canDrop after the built-in rules and takes its reason', () => {
    const r = move(tree(), 'e', 'a', 0, { canDrop: ({ node }) => (node.id === 'e' ? 'Сюда нельзя' : true) })
    expect(r).toMatchObject({ ok: false, reason: 'Сюда нельзя' })
  })

  it('works with other key names', () => {
    const t = [{ key: 'p', items: [] }, { key: 'q' }]
    const r = move(t, 'q', 'p', 0, { id: 'key', kids: 'items' })
    expect(r.nodes[0].items.map((n) => n.key)).toEqual(['q'])
  })
})

describe('step (the keyboard)', () => {
  it('moves up and down among brothers and refuses at the ends', () => {
    expect(step(tree(), 'e', 'up').nodes.map((n) => n.id)).toEqual(['e', 'a'])
    expect(step(tree(), 'a', 'up').ok).toBe(false)
    expect(step(tree(), 'e', 'down').ok).toBe(false)
  })

  it('puts a node into the brother above and out next to its parent', () => {
    expect(ids(step(tree(), 'e', 'in').nodes)).toEqual([['a', ['b', ['c']], ['d'], ['e']]])
    expect(ids(step(tree(), 'd', 'out').nodes)).toEqual([['a', ['b', ['c']]], ['d'], ['e']])
    expect(step(tree(), 'a', 'out').reason).toMatch(/верхний уровень/)
  })

  it('canStep disables what does nothing', () => {
    expect(canStep(tree(), 'a', 'up').ok).toBe(false)
    expect(canStep(tree(), 'e', 'up').ok).toBe(true)
  })
})

describe('rows / nest / payload', () => {
  it('lists the visible rows in display order, open branches only', () => {
    expect(rows(tree(), (id) => id === 'a').map((r) => [r.node.id, r.level])).toEqual([['a', 1], ['b', 2], ['d', 2], ['e', 1]])
    expect(rows(tree(), () => false).map((r) => r.node.id)).toEqual(['a', 'e'])
  })

  it('nests a flat list; a row whose parent is unknown goes to the top', () => {
    const flat = [{ id: 1, parentId: null }, { id: 2, parentId: 1 }, { id: 3, parentId: 99 }, { id: 4, parentId: 4 }]
    const nested = nest(flat)
    expect(nested.map((n) => n.id)).toEqual([1, 3, 4])
    expect(nested[0].children.map((n) => n.id)).toEqual([2])
  })

  it('builds the server payload for the groups that changed', () => {
    const r = move(tree(), 'e', 'a', 1)
    expect(payload(r.nodes, groupsOf(r))).toEqual([
      { id: 'a', parentId: null, position: 0 },
      { id: 'b', parentId: 'a', position: 0 },
      { id: 'e', parentId: 'a', position: 1 },
      { id: 'd', parentId: 'a', position: 2 },
    ])
  })
})

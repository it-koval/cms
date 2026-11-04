// The arithmetic of a tree of nodes that people move: pure functions, no Vue, no api. SortableTree.vue draws and listens; this decides.
// A tree is NESTED: [{ id, ...whatever, children: [ ... ] }] (the keys are `opts.id` / `opts.kids`, default id / children). Nothing is changed in place: every function
// that moves something answers with a new tree. A level is counted from 1: the top level is level 1, `maxDepth: 2` = a node and one level of children (a menu).
//
//   drop(nodes, id)                            the tree without that node and its branch
//   find(nodes, id)                            { node, parent, parentId, index, siblings, level } | null
//   height(node)                               how many levels the node and everything under it take (a node without children = 1)
//   move(nodes, id, parentId, index, opts)     { ok, reason, nodes, from:{parentId,index}, to:{parentId,index} }; `index` is the FINAL place among the children of the new parent
//   step(nodes, id, dir, opts)                 the same for the keyboard: dir = 'up' | 'down' | 'in' (into the brother above) | 'out' (next to its parent)
//   canStep(nodes, id, dir, opts)              { ok, reason } without doing it (to disable a button)
//   rows(nodes, isOpen, opts)                  the visible rows in display order: [{ node, level, parentId, index, count, hasKids, open }]
//   nest(list, opts)                           flat rows [{ id, parentId, ... }] (brothers in the order they come) → nested tree; a row whose parent is not in the list goes to the top
//   payload(nodes, groups, opts)               [{ id, parentId, position }] for the children of each parent id in `groups` (null = the top level): what the server takes
//
// opts: { id, kids, maxDepth, canDrop }.  canDrop({ node, parent, index, nodes }) → true | false | 'the reason in plain words'; it is asked after the built in rules pass.
import { t } from '@/i18n/index.js'

const O = (opts = {}) => ({ id: 'id', kids: 'children', maxDepth: Infinity, canDrop: null, ...opts })
const kidsOf = (n, o) => (Array.isArray(n[o.kids]) ? n[o.kids] : [])

export function find(nodes, id, opts) {
  const o = O(opts)
  const walk = (list, parent, level) => {
    for (let i = 0; i < list.length; i++) {
      const node = list[i]
      if (String(node[o.id]) === String(id)) return { node, parent, parentId: parent ? parent[o.id] : null, index: i, siblings: list, level }
      const hit = walk(kidsOf(node, o), node, level + 1)
      if (hit) return hit
    }
    return null
  }
  return walk(nodes || [], null, 1)
}

export function height(node, opts) {
  const o = O(opts)
  const kids = kidsOf(node, o)
  return 1 + (kids.length ? Math.max(...kids.map((k) => height(k, o))) : 0)
}

/** Is `id` inside the branch of `ancestorId` (or the same node)? */
export function within(nodes, id, ancestorId, opts) {
  const o = O(opts)
  const top = find(nodes, ancestorId, o)
  return !!top && (String(top.node[o.id]) === String(id) || !!find(kidsOf(top.node, o), id, o))
}

const without = (list, id, o) => list.filter((n) => String(n[o.id]) !== String(id)).map((n) => (kidsOf(n, o).length ? { ...n, [o.kids]: without(kidsOf(n, o), id, o) } : n))

function put(list, parentId, index, node, o) {
  if (parentId == null) {
    const out = [...list]
    out.splice(Math.max(0, Math.min(index, out.length)), 0, node)
    return out
  }
  return list.map((n) => {
    if (String(n[o.id]) === String(parentId)) {
      const kids = [...kidsOf(n, o)]
      kids.splice(Math.max(0, Math.min(index, kids.length)), 0, node)
      return { ...n, [o.kids]: kids }
    }
    return kidsOf(n, o).length ? { ...n, [o.kids]: put(kidsOf(n, o), parentId, index, node, o) } : n
  })
}

/** The tree without a node (and its branch). */
export const drop = (nodes, id, opts) => without(nodes, id, O(opts))

export function move(nodes, id, parentId, index, opts) {
  const o = O(opts)
  const at = find(nodes, id, o)
  const no = (reason) => ({ ok: false, reason, nodes, from: null, to: null })
  if (!at) return no(t('ui.tree_gone_moved'))
  const parent = parentId == null ? null : find(nodes, parentId, o)
  if (parentId != null && !parent) return no(t('ui.tree_gone_target'))
  if (parentId != null && within(nodes, parentId, id, o)) return no(t(String(parentId) === String(id) ? 'ui.tree_into_self' : 'ui.tree_into_branch'))
  const level = parent ? parent.level + 1 : 1
  if (level + height(at.node, o) - 1 > o.maxDepth) {
    return no(o.maxDepth <= 1 ? t('ui.tree_flat') : t('ui.tree_too_deep', { max: o.maxDepth }))
  }
  const base = without(nodes, id, o)
  const group = parent ? kidsOf(find(base, parentId, o).node, o) : base
  const final = Math.max(0, Math.min(Number.isInteger(index) ? index : group.length, group.length))
  const from = { parentId: at.parentId, index: at.index }
  const to = { parentId: parentId ?? null, index: final }
  if (String(from.parentId ?? '') === String(to.parentId ?? '') && from.index === to.index) return { ok: true, same: true, reason: '', nodes, from, to }
  if (typeof o.canDrop === 'function') {
    const verdict = o.canDrop({ node: at.node, parent: parent ? parent.node : null, index: final, nodes })
    if (verdict !== true && verdict !== undefined) return no(typeof verdict === 'string' && verdict ? verdict : t('ui.tree_not_allowed'))
  }
  return { ok: true, same: false, reason: '', nodes: put(base, parentId ?? null, final, at.node, o), from, to }
}

/** Where a key of the keyboard sends a node: { parentId, index } or a reason why nowhere. */
function aim(nodes, id, dir, o) {
  const at = find(nodes, id, o)
  if (!at) return { reason: t('ui.tree_gone_pick') }
  if (dir === 'up') return at.index > 0 ? { parentId: at.parentId, index: at.index - 1 } : { reason: t('ui.tree_first') }
  if (dir === 'down') return at.index < at.siblings.length - 1 ? { parentId: at.parentId, index: at.index + 1 } : { reason: t('ui.tree_last') }
  if (dir === 'in') {
    if (at.index === 0) return { reason: t('ui.tree_no_sibling') }
    const above = at.siblings[at.index - 1]
    return { parentId: above[o.id], index: kidsOf(above, o).length }
  }
  if (dir === 'out') {
    if (!at.parent) return { reason: t('ui.tree_top_level') }
    const up = find(nodes, at.parent[o.id], o)
    return { parentId: up.parentId, index: up.index + 1 }
  }
  return { reason: '' }
}

export function step(nodes, id, dir, opts) {
  const o = O(opts)
  const to = aim(nodes, id, dir, o)
  if (to.reason !== undefined) return { ok: false, reason: to.reason, nodes, from: null, to: null }
  return move(nodes, id, to.parentId, to.index, o)
}

export function canStep(nodes, id, dir, opts) {
  const r = step(nodes, id, dir, opts)
  return { ok: r.ok && !r.same, reason: r.reason }
}

export function rows(nodes, isOpen = () => true, opts) {
  const o = O(opts)
  const out = []
  const walk = (list, level, parentId) => {
    list.forEach((node, index) => {
      const kids = kidsOf(node, o)
      const open = kids.length > 0 && !!isOpen(node[o.id])
      out.push({ node, level, parentId, index, count: list.length, hasKids: kids.length > 0, open })
      if (open) walk(kids, level + 1, node[o.id])
    })
  }
  walk(nodes || [], 1, null)
  return out
}

export function nest(list, opts) {
  const o = O(opts)
  const known = new Set((list || []).map((r) => String(r[o.id])))
  const made = new Map((list || []).map((r) => [String(r[o.id]), { ...r, [o.kids]: [] }]))
  const top = []
  ;(list || []).forEach((r) => {
    const p = r.parentId == null || r.parentId === '' ? null : String(r.parentId)
    const node = made.get(String(r[o.id]))
    if (p && known.has(p) && p !== String(r[o.id])) made.get(p)[o.kids].push(node)
    else top.push(node)
  })
  return top
}

export function payload(nodes, groups, opts) {
  const o = O(opts)
  const out = []
  const seen = new Set()
  ;[...new Set(groups.map((g) => (g == null ? '' : String(g))))].forEach((g) => {
    const list = g === '' ? nodes : kidsOf((find(nodes, g, o) || { node: {} }).node, o)
    list.forEach((n, position) => {
      if (seen.has(String(n[o.id]))) return
      seen.add(String(n[o.id]))
      out.push({ id: n[o.id], parentId: g === '' ? null : g, position })
    })
  })
  return out
}

/** The parents whose children changed by a move (the old and the new): what `payload` needs. */
export const groupsOf = (result) => (result && result.from ? [result.from.parentId, result.to.parentId] : [])

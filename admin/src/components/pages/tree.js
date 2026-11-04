// Page tree helpers: order, levels, descendants, targets of a row click.

/** Where a click on a page leads: every page (products and articles too) opens in the page editor. */
export const pageHref = (p) => `/pages/${p.id}`

/** Children lookup + roots. Pages whose parent no longer exists count as roots. */
export function buildTree(pages) {
  const ids = new Set(pages.map((p) => p.id))
  const kids = new Map()
  const roots = []
  pages.forEach((p) => {
    if (p.parentId && ids.has(p.parentId)) {
      if (!kids.has(p.parentId)) kids.set(p.parentId, [])
      kids.get(p.parentId).push(p)
    } else roots.push(p)
  })
  return { roots, kids }
}

/** Rows of the tree in display order: [{ p, lvl, kids }]. A branch is walked only when `isOpen(id)`. */
export function treeRows(pages, isOpen) {
  const { roots, kids } = buildTree(pages)
  const out = []
  ;(function walk(list, lvl) {
    list.forEach((p) => {
      const k = kids.get(p.id) || []
      out.push({ p, lvl, kids: k.length })
      if (k.length && isOpen(p.id)) walk(k, lvl + 1)
    })
  })(roots, 0)
  return out
}

/** Ids of pages that have children (to expand / collapse everything). */
export function parentIds(pages) {
  return [...buildTree(pages).kids.keys()]
}

/** Id of `id` and everything below it. */
export function subtreeIds(pages, id) {
  const { kids } = buildTree(pages)
  const out = new Set([id])
  ;(function walk(x) { (kids.get(x) || []).forEach((c) => { out.add(c.id); walk(c.id) }) })(id)
  return out
}

/** All pages in tree order with their level, for <select> options. `skip` = Set of ids to leave out (with their branches). */
export function flatOptions(pages, skip = new Set()) {
  const { roots, kids } = buildTree(pages)
  const out = []
  ;(function walk(list, lvl) {
    list.forEach((p) => {
      if (skip.has(p.id)) return
      out.push({ p, lvl })
      walk(kids.get(p.id) || [], lvl + 1)
    })
  })(roots, 0)
  return out
}

export const indent = (lvl) => '   '.repeat(lvl)

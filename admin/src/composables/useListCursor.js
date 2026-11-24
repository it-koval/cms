import { shallowRef } from 'vue'

// Adjacent pages use the last displayed result. Numbered jumps and changes to filters start a new traversal.
export function useListCursor() {
  const cursors = shallowRef(null)
  let cursor = ''

  function position(next, reset) {
    const current = reset ? null : cursors.value
    cursor = (current && (next === current.page + 1 ? current.nextCursor : next === current.page - 1 ? current.previousCursor : null)) || ''
  }

  function accept(result) {
    cursors.value = Object.hasOwn(result, 'nextCursor') ? { page: result.page, nextCursor: result.nextCursor, previousCursor: result.previousCursor } : null
  }

  return { cursors, position, accept, parameters: () => cursor ? { cursor } : {} }
}

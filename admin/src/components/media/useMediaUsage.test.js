import { afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope, ref } from 'vue'
import { useMediaUsage } from './useMediaUsage.js'

const scopes = []
afterEach(() => { for (const scope of scopes.splice(0)) scope.stop() })
function setup(fetchUsage) {
  const selected = ref(null)
  const scope = effectScope()
  scopes.push(scope)
  const state = scope.run(() => useMediaUsage(selected, fetchUsage))
  return { selected, ...state }
}
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => { resolve = yes; reject = no })
  return { promise, resolve, reject }
}
const settle = async () => { await Promise.resolve(); await Promise.resolve() }

describe('media usage verification', () => {
  it('keeps loading and failure distinct from a verified empty result, then permits a retry', async () => {
    const first = deferred()
    const fetchUsage = vi.fn().mockReturnValueOnce(first.promise).mockResolvedValueOnce([])
    const state = setup(fetchUsage)
    state.selected.value = 'photo'
    expect(state.usage.value).toBe(null)
    expect(state.usageError.value).toBe(false)
    first.reject(new Error('network unavailable'))
    await settle()
    expect(state.usage.value).toBe(null)
    expect(state.usageError.value).toBe(true)
    await state.retryUsage()
    expect(state.usage.value).toEqual([])
    expect(state.usageError.value).toBe(false)
  })

  it('ignores a stale result even when the same file has been reopened', async () => {
    const old = deferred(), other = deferred(), current = deferred()
    const state = setup(vi.fn().mockReturnValueOnce(old.promise).mockReturnValueOnce(other.promise).mockReturnValueOnce(current.promise))
    state.selected.value = 'a'
    state.selected.value = 'b'
    state.selected.value = 'a'
    old.resolve([])
    other.reject(new Error('old failure'))
    await settle()
    expect(state.usage.value).toBe(null)
    expect(state.usageError.value).toBe(false)
    const places = [{ label: 'Published home page', to: '/pages/home' }]
    current.resolve(places)
    await settle()
    expect(state.usage.value).toEqual(places)
  })

  it('invalidates an in-flight check immediately when the drawer closes', async () => {
    const request = deferred()
    const state = setup(() => request.promise)
    state.selected.value = 'a'
    state.selected.value = null
    request.resolve([])
    await settle()
    expect(state.usage.value).toBe(null)
    expect(state.usageError.value).toBe(false)
  })
})

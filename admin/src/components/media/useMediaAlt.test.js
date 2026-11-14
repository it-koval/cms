import { effectScope, ref } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useMediaAlt } from './useMediaAlt.js'

let scope
afterEach(() => scope.stop())
function setup() {
  const item = ref({ id: 'a', type: 'image', alt: 'old' })
  let reply
  const write = vi.fn(() => new Promise((resolve) => { reply = resolve }))
  scope = effectScope()
  const model = scope.run(() => useMediaAlt(item, ref(true), write))
  model.alt.value = 'new'
  return { item, model, write, finish: (ok) => reply(ok) }
}

describe('closing a file with an alt edit', () => {
  it('waits for an existing save before allowing the route to leave', async () => {
    const { model, write, finish } = setup()
    const manual = model.saveAlt()
    const leave = model.saveAlt()
    expect(write).toHaveBeenCalledTimes(1)
    expect(write).toHaveBeenCalledWith('a', 'new')
    finish(true)
    expect(await Promise.all([manual, leave])).toEqual([true, true])
  })

  it('keeps the drawer when the save fails or the user continues typing during it', async () => {
    const { model, finish } = setup()
    const failed = model.saveAlt()
    finish(false)
    expect(await failed).toBe(false)
    const retry = model.saveAlt()
    const leave = model.saveAlt()
    model.alt.value = 'newer'
    finish(true)
    expect(await Promise.all([retry, leave])).toEqual([false, false])
    expect(model.alt.value).toBe('newer')
    expect(model.altDirty.value).toBe(true)
  })

  it('rejects a late close after opening a different file, even when its alt text matches', async () => {
    const { model, item, finish } = setup()
    const close = model.saveAlt()
    item.value = { id: 'b', type: 'image', alt: 'new' }
    finish(true)
    expect(await close).toBe(false)
    expect(model.alt.value).toBe('new')
  })
})

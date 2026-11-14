import { describe, expect, it, vi } from 'vitest'
import { get, save } from './bots.js'
import { http } from './http.js'

vi.mock('./http.js', () => ({ http: { get: vi.fn(), put: vi.fn() } }))

describe('bot settings API', () => {
  it('reads metadata and sends credential patches through the dedicated endpoint', async () => {
    await get()
    expect(http.get).toHaveBeenCalledWith('settings/bots')
    const patch = { max: { clear: true } }
    await save(patch)
    expect(http.put).toHaveBeenCalledWith('settings/bots', patch)
  })
})

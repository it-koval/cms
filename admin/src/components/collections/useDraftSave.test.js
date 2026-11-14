import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('vue', async (original) => ({ ...(await original()), onBeforeUnmount: () => {} }))
vi.mock('@/api/index.js', async () => {
  const http = await vi.importActual('@/api/http.js')
  return { isConflict: http.isConflict, whileClosing: http.whileClosing }
})

import { ApiError, http } from '@/api/http.js'
import { useDraftSave } from './useDraftSave.js'

describe('useDraftSave: the version of the draft', () => {
  beforeEach(() => {
    vi.stubGlobal('window', { addEventListener() {}, removeEventListener() {} })
  })

  function saverThatNumbersSaves() {
    const bases = []
    const save = useDraftSave(async (_, base) => {
      bases.push(base)
      return { version: 'v' + (bases.length + 1) }
    }, { delay: 0 })
    return { save, bases }
  }

  it('every save names the version the last answer brought', async () => {
    const { save, bases } = saverThatNumbersSaves()
    save.setVersion('v1')
    save.schedule({ n: 1 })
    await save.flush()
    save.schedule({ n: 2 })
    await save.flush()
    expect(bases).toEqual(['v1', 'v2'])
  })

  it('a load that went out before a save does not bring the older version back', async () => {
    const { save, bases } = saverThatNumbersSaves()
    save.setVersion('v1')
    const since = save.saves() // the load goes out
    save.schedule({ n: 1 })
    await save.flush()
    save.setVersion('v1', since) // its answer comes after the save: without the check the next save would be refused as a conflict with itself
    save.schedule({ n: 2 })
    await save.flush()
    expect(bases).toEqual(['v1', 'v2'])
  })

  it('a load with no save on its way sets the version', async () => {
    const { save, bases } = saverThatNumbersSaves()
    save.setVersion('v1')
    save.setVersion('v9', save.saves())
    save.schedule({ n: 1 })
    await save.flush()
    expect(bases).toEqual(['v9'])
  })
})

describe('useDraftSave: flush says whether everything is saved', () => {
  let listeners
  beforeEach(() => {
    listeners = {}
    vi.stubGlobal('window', { addEventListener: (n, f) => { listeners[n] = f }, removeEventListener() {}, location: {} })
  })

  it('resolves true when the save went through and when nothing waits', async () => {
    const save = useDraftSave(async () => ({ version: 'v2' }), { delay: 0 })
    expect(await save.flush()).toBe(true)
    save.schedule({ n: 1 })
    expect(await save.flush()).toBe(true)
    expect(save.state.value).toBe('saved')
  })

  it('resolves false when the save failed, keeps the edit and tries it again on the next flush', async () => {
    const saveFn = vi.fn()
      .mockRejectedValueOnce(new ApiError('Сервер недоступен', 503))
      .mockResolvedValue({ version: 'v2' })
    const save = useDraftSave(saveFn, { delay: 0 })
    save.schedule({ n: 1 })
    expect(await save.flush()).toBe(false)
    expect(save.state.value).toBe('error')
    expect(save.error.value).toBe('Сервер недоступен')
    expect(await save.flush()).toBe(true)
    expect(saveFn).toHaveBeenCalledTimes(2)
    expect(saveFn.mock.calls[1][0]).toEqual({ n: 1 })
    expect(save.state.value).toBe('saved')
  })

  it('picks up an edit typed while the save was on its way', async () => {
    let release
    const sent = []
    const save = useDraftSave((p) => { sent.push(p); return sent.length === 1 ? new Promise((r) => { release = () => r({ version: 'v2' }) }) : Promise.resolve({ version: 'v3' }) }, { delay: 0 })
    save.schedule({ n: 1 })
    const done = save.flush()
    await Promise.resolve()
    save.schedule({ n: 2 })
    release()
    expect(await done).toBe(true)
    expect(sent).toEqual([{ n: 1 }, { n: 2 }])
  })

  it('does not resolve while a later save is on its way after the one it waited for failed', async () => {
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
    const gates = []
    const sent = []
    const save = useDraftSave((p) => { sent.push(p); return new Promise((resolve, reject) => gates.push({ resolve, reject })) }, { delay: 5 })
    save.schedule({ n: 'A' })
    await sleep(15) // the timer ran: A is on its way
    save.schedule({ n: 'B' })
    await sleep(15) // the timer ran again: its run waits for A
    let result
    const done = save.flush().then((r) => { result = r })
    gates[0].reject(new ApiError('Сервер недоступен', 503)) // A fails; the run for B takes over
    await sleep(15)
    expect(sent).toEqual([{ n: 'A' }, { n: 'B' }])
    expect(result).toBeUndefined() // B is on its way: nothing is saved yet
    gates[1].resolve({ version: 'v3' })
    await done
    expect(result).toBe(true)
    expect(save.state.value).toBe('saved')
  })

  it('resolves false while a conflict waits and sends nothing', async () => {
    const saveFn = vi.fn().mockRejectedValue(new ApiError('Изменили', 409, { code: 'conflict', current: { version: 'v9' } }))
    const save = useDraftSave(saveFn, { delay: 0 })
    save.schedule({ n: 1 })
    expect(await save.flush()).toBe(false)
    expect(save.conflict.value).toEqual({ version: 'v9' })
    expect(await save.flush()).toBe(false)
    expect(saveFn).toHaveBeenCalledTimes(1)
  })
})

describe('useDraftSave: a closing tab', () => {
  let listeners
  let fetchMock
  beforeEach(() => {
    listeners = {}
    vi.stubGlobal('window', { addEventListener: (n, f) => { listeners[n] = f }, removeEventListener() {}, location: {} })
    fetchMock = vi.fn(async () => new Response(JSON.stringify({ version: 'v2' }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
  })
  const saver = () => useDraftSave((payload) => http.put('collections/news/1', payload), { delay: 10000 })

  it('sends a small last edit with keepalive', () => {
    const save = saver()
    save.schedule({ title: 'Заголовок' })
    listeners.beforeunload({ preventDefault() {} })
    expect(fetchMock.mock.calls[0][1].keepalive).toBe(true)
  })

  it('does not use keepalive for a body over the limit in bytes, though it is under 60000 characters', () => {
    const save = saver()
    save.schedule({ body: 'я'.repeat(31000) })
    listeners.beforeunload({ preventDefault() {} })
    expect(fetchMock.mock.calls[0][1].keepalive).toBe(false)
  })

  it('asks before leaving a checked save in flight and does not duplicate the request', async () => {
    let finish
    const write = vi.fn(() => new Promise((resolve) => { finish = resolve }))
    const save = useDraftSave(write)
    save.setVersion('v1')
    save.schedule({ title: 'A' })
    const pending = save.flush()
    const event = { preventDefault: vi.fn() }
    listeners.beforeunload(event)
    expect(event.preventDefault).toHaveBeenCalledOnce()
    expect(write.mock.calls).toEqual([[{ title: 'A' }, 'v1']])
    finish({ version: 'v2' })
    expect(await pending).toBe(true)
  })

  it('keeps newer edits queued until the previous version is known if the user stays', async () => {
    let finish
    const write = vi.fn().mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
      .mockResolvedValueOnce({ version: 'v3' })
    const save = useDraftSave(write)
    save.setVersion('v1')
    save.schedule({ title: 'A' })
    const pending = save.flush()
    save.schedule({ title: 'B' })
    const event = { preventDefault: vi.fn() }
    listeners.beforeunload(event)
    expect(event.preventDefault).toHaveBeenCalledOnce()
    expect(write).toHaveBeenCalledTimes(1)
    finish({ version: 'v2' })
    expect(await pending).toBe(true)
    expect(write.mock.calls[1]).toEqual([{ title: 'B' }, 'v2'])
    expect(save.version()).toBe('v3')
  })

  it('keeps the newest edit pending when a save conflicts as the tab is closing', async () => {
    let fail
    const write = vi.fn().mockImplementationOnce(() => new Promise((_, reject) => { fail = reject }))
      .mockResolvedValueOnce({ version: 'v4' })
    const save = useDraftSave(write)
    save.setVersion('v1')
    save.schedule({ title: 'A' })
    const pending = save.flush()
    save.schedule({ title: 'B' })
    listeners.beforeunload({ preventDefault() {} })
    fail(new ApiError('Changed', 409, { code: 'conflict', current: { version: 'v3' } }))
    expect(await pending).toBe(false)
    expect(write).toHaveBeenCalledTimes(1)
    await save.keepMine()
    expect(write.mock.calls[1]).toEqual([{ title: 'B' }, 'v3'])
  })

  it('keeps the reviewed snapshot available after a publish conflict with no local save pending', async () => {
    const write = vi.fn().mockResolvedValue({ version: 'v3' })
    const save = useDraftSave(write)
    save.setVersion('v1')
    save.publishConflict(new ApiError('Changed', 409, { code: 'conflict', current: { version: 'v2' } }), { title: 'Reviewed' })
    expect(await save.flush()).toBe(false)
    expect(write).not.toHaveBeenCalled()
    await save.keepMine()
    expect(write.mock.calls).toEqual([[{ title: 'Reviewed' }, 'v2']])
    expect(save.version()).toBe('v3')
  })

  it('asks the browser first when the last save failed', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('offline'))
    const save = saver()
    save.schedule({ title: 'a' })
    expect(await save.flush()).toBe(false)
    const e = { preventDefault: vi.fn() }
    listeners.beforeunload(e)
    expect(e.preventDefault).toHaveBeenCalled()
  })

  it('an ordinary autosave is not keepalive, whatever the size', async () => {
    const save = useDraftSave((payload) => http.put('collections/news/1', payload), { delay: 0 })
    save.schedule({ title: 'коротко' })
    await save.flush()
    save.schedule({ body: 'я'.repeat(40000) })
    await save.flush()
    expect(fetchMock.mock.calls.map((c) => c[1].keepalive)).toEqual([false, false])
  })
})

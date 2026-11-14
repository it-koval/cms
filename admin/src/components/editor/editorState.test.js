import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { saveDraft, publish, getDraft } = vi.hoisted(() => ({ saveDraft: vi.fn(), publish: vi.fn(), getDraft: vi.fn() }))

vi.mock('@/api/index.js', async () => {
  const http = await vi.importActual('@/api/http.js')
  return {
    api: {
      pages: {
        getDraft, publish, list: async () => [], submit: vi.fn(), returnToAuthor: vi.fn(), revert: vi.fn(),
        saveDraft: (id, locale, data, baseVersion) => saveDraft(id, locale, data, baseVersion),
      },
      proposals: { list: async () => [], accept: async () => ({}), reject: async () => ({}) },
    },
    isConflict: http.isConflict,
    whileClosing: http.whileClosing,
  }
})
vi.mock('@/stores/app.js', () => ({ app: { locale: 'ru', languages: { main: 'ru', list: [{ code: 'ru', name: 'Русский' }] }, user: null }, can: () => true }))

import { ApiError, http } from '@/api/http.js'
import { useEditorState } from './editorState.js'

const draft = () => ({ id: 'p1', title: 'Страница', template: '', version: 'v1', sections: [{ id: 's1', type: 'text', hidden: false, data: { body: 'a' } }], fields: {} })

async function openEditor() {
  getDraft.mockResolvedValue({ translated: true, locales: {}, shell: { id: 'p1', template: '' }, page: draft(), published: null })
  const ed = useEditorState('p1', 'ru')
  await ed.load()
  return ed
}

beforeEach(() => {
  vi.stubGlobal('window', { addEventListener() {}, removeEventListener() {}, location: {} })
  vi.stubGlobal('innerWidth', 1400)
  saveDraft.mockReset().mockResolvedValue({ version: 'v2', updatedAt: 'now' })
  publish.mockReset().mockResolvedValue({})
})
afterEach(() => { vi.unstubAllGlobals() })

describe('page editor: a failed save is not a saved one', () => {
  it('flush resolves false while the save fails and true once it goes through', async () => {
    const ed = await openEditor()
    saveDraft.mockRejectedValueOnce(new ApiError('Сервер недоступен', 503))
    ed.updateData('s1', { body: 'b' })
    expect(await ed.flush()).toBe(false)
    expect(ed.save).toBe('error')
    expect(await ed.flush()).toBe(true) // the failed edit waited and is sent again
    expect(saveDraft).toHaveBeenCalledTimes(2)
    expect(ed.save).toBe('saved')
  })

  it('flush resolves true when there is nothing to save', async () => {
    const ed = await openEditor()
    expect(await ed.flush()).toBe(true)
    expect(saveDraft).not.toHaveBeenCalled()
  })

  it('flushAll rejects with the reason and the code of a failed save', async () => {
    const ed = await openEditor()
    saveDraft.mockRejectedValue(new ApiError('Нет связи', 0))
    ed.updateData('s1', { body: 'b' })
    const err = await ed.flushAll().catch((e) => e)
    expect(err.code).toBe('save_failed')
    expect(err.message).toContain('Нет связи')
  })

  it('a conflict still stops flushAll with its own message, not as a failed save', async () => {
    const ed = await openEditor()
    saveDraft.mockRejectedValue(new ApiError('Изменили', 409, { code: 'conflict', current: { version: 'v9' } }))
    ed.updateData('s1', { body: 'b' })
    const err = await ed.flushAll().catch((e) => e)
    expect(err).toBeInstanceOf(Error)
    expect(err.code).toBeUndefined()
    expect(await ed.flush()).toBe(false)
  })

  it('publish does not reach the API when the last edit failed to save', async () => {
    const ed = await openEditor()
    saveDraft.mockRejectedValue(new ApiError('Ошибка сервера', 500))
    ed.updateData('s1', { body: 'b' })
    const r = await ed.publish()
    expect(r.ok).toBe(false)
    expect(publish).not.toHaveBeenCalled()
  })

  it('publish goes on once the save goes through', async () => {
    const ed = await openEditor()
    ed.updateData('s1', { body: 'b' })
    getDraft.mockResolvedValue({ translated: true, locales: {}, shell: {}, page: { ...draft(), version: 'v2', status: 'published' }, published: draft() })
    expect((await ed.publish()).ok).toBe(true)
    expect(publish).toHaveBeenCalledWith('p1', 'ru', 'v2')
  })

  it('shows a conflict before publishing a stale reviewed version even when no local edit is pending', async () => {
    const ed = await openEditor()
    publish.mockRejectedValueOnce(new ApiError('Changed', 409, { code: 'conflict', current: { version: 'v5' } }))
    expect((await ed.publish()).ok).toBe(false)
    expect(publish).toHaveBeenCalledWith('p1', 'ru', 'v1')
    expect(ed.conflict.version).toBe('v5')
    expect(saveDraft).not.toHaveBeenCalled()
    await ed.keepMine()
    expect(saveDraft.mock.calls[0][3]).toBe('v5')
  })
})

describe('page editor: an edit typed while the publish is on its way', () => {
  it('stays on screen and is saved by the next autosave', async () => {
    const ed = await openEditor()
    ed.updateData('s1', { body: 'b' })
    publish.mockImplementation(async () => { ed.updateData('s1', { body: 'typed during publish' }); return {} })
    getDraft.mockResolvedValue({ translated: true, locales: {}, shell: {}, page: { ...draft(), version: 'v2', status: 'published' }, published: draft() })
    expect((await ed.publish()).ok).toBe(true)
    expect(ed.page.sections[0].data.body).toBe('typed during publish')
    expect(ed.save).toBe('busy')
    expect(await ed.flush()).toBe(true)
    expect(saveDraft.mock.calls.at(-1)[2].sections[0].data.body).toBe('typed during publish')
  })
})

describe('page editor: the bar after a publish', () => {
  it('keeps the error of an edit typed during the publish that failed to save', async () => {
    const ed = await openEditor()
    saveDraft.mockRejectedValue(new ApiError('Нет связи', 0))
    publish.mockImplementation(async () => { ed.updateData('s1', { body: 'typed' }); await ed.flush(); return {} })
    getDraft.mockResolvedValue({ translated: true, locales: {}, shell: {}, page: { ...draft(), status: 'published' }, published: draft() })
    expect((await ed.publish()).ok).toBe(true)
    expect(ed.save).toBe('error')
  })
})

describe('page editor: keepalive only for a closing tab and only when the body fits', () => {
  let fetchMock
  beforeEach(() => {
    fetchMock = vi.fn(async () => new Response(JSON.stringify({ version: 'v2', updatedAt: 'now' }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    saveDraft.mockImplementation((id, locale, data, baseVersion) => http.put(`pages/${id}/draft`, { ...data, baseVersion }, { locale }))
  })
  const bigText = (n) => 'я'.repeat(n)

  it('an ordinary autosave of a long Russian text is not keepalive', async () => {
    const ed = await openEditor()
    ed.updateData('s1', { body: bigText(33000) }) // 33000 characters, 66000 bytes: over the limit for keepalive
    expect(await ed.flush()).toBe(true)
    expect(fetchMock.mock.calls[0][1].keepalive).toBe(false)
  })

  it('an ordinary small autosave is not keepalive either', async () => {
    const ed = await openEditor()
    ed.updateData('s1', { body: 'коротко' })
    await ed.flush()
    expect(fetchMock.mock.calls[0][1].keepalive).toBe(false)
  })

  it('the save of a closing tab is keepalive when the body is small', async () => {
    const ed = await openEditor()
    ed.updateData('s1', { body: 'коротко' })
    ed.saveNow({ preventDefault() {} })
    expect(fetchMock.mock.calls[0][1].keepalive).toBe(true)
  })

  it('the save of a closing tab is not keepalive when the body is over the limit in bytes', async () => {
    const ed = await openEditor()
    ed.updateData('s1', { body: bigText(31000) }) // under 60000 characters, over 60000 bytes
    ed.saveNow({ preventDefault() {} })
    expect(fetchMock.mock.calls[0][1].keepalive).toBe(false)
  })

  it('asks before closing with a save in flight and checks the next edit against its answer', async () => {
    let finish
    fetchMock.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
    const ed = await openEditor()
    ed.updateData('s1', { body: 'first edit' })
    const pending = ed.flush()
    ed.updateData('s1', { body: 'second edit' })
    const event = { preventDefault: vi.fn() }
    ed.saveNow(event)
    expect(event.preventDefault).toHaveBeenCalledOnce()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    finish(new Response(JSON.stringify({ version: 'v2' })))
    expect(await pending).toBe(true)
    expect(saveDraft.mock.calls.map(([, , data, version]) => [data.sections[0].data.body, version]))
      .toEqual([['first edit', 'v1'], ['second edit', 'v2']])
  })

  it('includes a checked keepalive save in the queue if the user stays and publishes', async () => {
    let finish
    fetchMock.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
    const ed = await openEditor()
    ed.updateData('s1', { body: 'reviewed' })
    ed.saveNow({ preventDefault() {} })
    const publishing = ed.publish()
    expect(publish).not.toHaveBeenCalled()
    finish(new Response(JSON.stringify({ version: 'v2' })))
    expect((await publishing).ok).toBe(true)
    expect(publish).toHaveBeenCalledWith('p1', 'ru', 'v2')
  })

  it('a closing tab with nothing to save and nothing on its way sends nothing', async () => {
    const ed = await openEditor()
    ed.saveNow({ preventDefault() {} })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('a closing tab whose last save failed makes the browser ask', async () => {
    const ed = await openEditor()
    saveDraft.mockRejectedValueOnce(new ApiError('Нет связи', 0))
    ed.updateData('s1', { body: 'b' })
    await ed.flush()
    const e = { preventDefault: vi.fn() }
    ed.saveNow(e)
    expect(e.preventDefault).toHaveBeenCalled()
  })
})

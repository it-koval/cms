import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, KEEPALIVE_MAX_BYTES, http, isConflict, request, setCsrf, whileClosing } from './http.js'

const reply = (status, body, headers = {}) => new Response(body === undefined ? null : JSON.stringify(body), { status, headers })
let fetchMock
beforeEach(() => { fetchMock = vi.fn(); vi.stubGlobal('fetch', fetchMock); globalThis.window = { ITK_ADMIN: {} } })
afterEach(() => { vi.unstubAllGlobals(); delete globalThis.window })

describe('request', () => {
  it('goes to /admin/api by default (npm run dev has no shell to say otherwise) and sends JSON', async () => {
    fetchMock.mockImplementation(() => reply(200, { ok: true }))
    expect(await http.post('pages', { title: 'A' }, { locale: 'ru' })).toEqual({ ok: true })
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/admin/api/pages?locale=ru')
    expect(init.method).toBe('POST')
    expect(init.headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(init.body)).toEqual({ title: 'A' })
  })

  it('takes the address of the admin from the server shell', async () => {
    globalThis.window = { ITK_ADMIN: { apiBase: '/cp/api/' } }
    fetchMock.mockImplementation(() => reply(200, []))
    await http.get('pages')
    expect(fetchMock.mock.calls[0][0]).toBe('/cp/api/pages')
  })

  it('writes arrays and objects of a query the way PHP reads them', async () => {
    fetchMock.mockImplementation(() => reply(200, []))
    await http.get('collections/x', { filter: { status: 'draft', date: { from: '2026-01-01' } }, tags: ['a', 'b'], empty: '', no: false, yes: true })
    expect(decodeURIComponent(fetchMock.mock.calls[0][0])).toBe('/admin/api/collections/x?filter[status]=draft&filter[date][from]=2026-01-01&tags[]=a&tags[]=b&yes=1')
  })

  it('sends the CSRF token with writes only', async () => {
    setCsrf('tok-1')
    fetchMock.mockImplementation(() => reply(200, {}))
    await http.get('me')
    await http.put('prefs/a', { value: 1 })
    expect(fetchMock.mock.calls[0][1].headers['X-CSRF-Token']).toBeUndefined()
    expect(fetchMock.mock.calls[1][1].headers['X-CSRF-Token']).toBe('tok-1')
  })

  it('turns the answers of the server into ApiError with its message and details', async () => {
    fetchMock.mockImplementation(() => reply(409, { error: 'Занято', details: { code: 'conflict' } }))
    const err = await http.put('pages/1/draft', {}).catch((e) => e)
    expect(err).toBeInstanceOf(ApiError)
    expect(err).toMatchObject({ message: 'Занято', status: 409 })
    expect(isConflict(err)).toBe(true)
    fetchMock.mockImplementation(() => reply(404, undefined))
    expect(await http.get('pages/9').catch((e) => e.message)).toBe('Не найдено')
  })

  it('says it plainly when there is no connection', async () => {
    fetchMock.mockRejectedValue(new TypeError('failed'))
    expect(await request('GET', 'x').catch((e) => e.status)).toBe(0)
  })
})

describe('keepalive: only for the write of a closing tab and only while the body fits', () => {
  beforeEach(() => fetchMock.mockImplementation(() => reply(200, {})))
  const sentKeepalive = () => fetchMock.mock.calls.map((c) => c[1].keepalive)
  // 31000 Cyrillic letters: under the old limit of 60000 characters, 62000 bytes in UTF-8
  const cyrillic = (n) => ({ body: 'я'.repeat(n) })

  it('an ordinary write is not keepalive, small or big', async () => {
    await http.put('pages/1/draft', { title: 'коротко' })
    await http.put('pages/1/draft', cyrillic(33000))
    await http.post('pages', cyrillic(100))
    expect(sentKeepalive()).toEqual([false, false, false])
  })

  it('a small write started while the tab closes is keepalive', async () => {
    await whileClosing(() => http.put('pages/1/draft', { title: 'Заголовок' }))
    expect(sentKeepalive()).toEqual([true])
  })

  it('a write of a closing tab that is over the limit in bytes is not keepalive', async () => {
    await whileClosing(() => http.put('pages/1/draft', cyrillic(31000)))
    await whileClosing(() => http.put('pages/1/draft', cyrillic(KEEPALIVE_MAX_BYTES)))
    expect(sentKeepalive()).toEqual([false, false])
  })

  it('a body of Latin letters of the same length still fits', async () => {
    await whileClosing(() => http.put('pages/1/draft', { body: 'a'.repeat(31000) }))
    expect(sentKeepalive()).toEqual([true])
  })

  it('GET and files are never keepalive, and the closing mode ends with the call', async () => {
    await whileClosing(() => http.get('pages'))
    await whileClosing(() => http.upload('media', new FormData()))
    await http.put('pages/1/draft', { title: 'после' })
    expect(sentKeepalive()).toEqual([false, false, false])
  })

  it('the closing mode ends even when the call throws', () => {
    expect(() => whileClosing(() => { throw new Error('x') })).toThrow('x')
  })
})

describe('npm run dev: the CSRF token', () => {
  // the token lives in the module: a fresh copy of it starts without one (and the fresh copy of the texts has to be loaded too: http.js reads them when it starts)
  const fresh = async () => { vi.resetModules(); await (await import('@/i18n/index.js')).initI18n('ru'); return import('./http.js') }

  it('explains a refused write when the server never handed the token over', async () => {
    const { http, DEV_CSRF_HINT } = await fresh()
    fetchMock.mockImplementation(() => reply(403, { error: 'Страница устарела. Обновите её и повторите действие', details: { code: 'csrf' } }))
    const err = await http.put('prefs/a', {}).catch((e) => e)
    expect(err.status).toBe(403)
    expect(err.message).toBe(DEV_CSRF_HINT)
    expect(DEV_CSRF_HINT).toMatch(/csrf/i)
    expect(DEV_CSRF_HINT).toMatch(/\/me/)
    expect(DEV_CSRF_HINT).toMatch(/обновите бандл/i)
    expect(DEV_CSRF_HINT).not.toMatch(/MetaController/)
  })

  it('leaves the words of the server when a token was sent and was simply wrong', async () => {
    const { http, setCsrf } = await fresh()
    setCsrf('stale')
    fetchMock.mockImplementation(() => reply(403, { error: 'Страница устарела. Обновите её и повторите действие', details: { code: 'csrf' } }))
    const err = await http.put('prefs/a', {}).catch((e) => e)
    expect(err.message).toBe('Страница устарела. Обновите её и повторите действие')
  })
})

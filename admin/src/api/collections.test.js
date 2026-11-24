import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { list } from './collections.js'
import { http } from './http.js'

vi.mock('./http.js', () => ({ http: { get: vi.fn() } }))

beforeEach(() => { http.get.mockReset() })
afterEach(() => { vi.restoreAllMocks() })

describe('collections list', () => {
  it('collects two pages into a flat array and preserves options on every request', async () => {
    const first = Array.from({ length: 200 }, (_, i) => ({ id: String(i + 1) }))
    const second = [{ id: '201' }]
    http.get.mockResolvedValueOnce({ items: first, total: 201 })
      .mockResolvedValueOnce({ items: second, total: 201 })

    expect(await list('news/test', { q: 'launch', status: 'draft', locale: 'ru', onlyTranslated: true })).toEqual([...first, ...second])
    expect(http.get).toHaveBeenCalledTimes(2)
    for (let page = 1; page <= 2; page++) {
      expect(http.get).toHaveBeenNthCalledWith(page, 'collections/news%2Ftest', {
        q: 'launch', status: 'draft', locale: 'ru', onlyTranslated: true, page, perPage: 200,
      })
    }
  })

  it('stops at total even when the last page is full', async () => {
    const items = Array.from({ length: 200 }, (_, i) => ({ id: String(i + 1) }))
    http.get.mockResolvedValue({ items, total: 200 })

    expect(await list('news')).toEqual(items)
    expect(http.get).toHaveBeenCalledExactlyOnceWith('collections/news', {
      q: '', status: '', locale: undefined, onlyTranslated: false, page: 1, perPage: 200,
    })
  })

  it('stops on an empty first page', async () => {
    http.get.mockResolvedValue({ items: [], total: 0 })

    expect(await list('news')).toEqual([])
    expect(http.get).toHaveBeenCalledTimes(1)
  })

  it('returns collected rows when a later page is empty before total is reached', async () => {
    const items = [{ id: '1' }]
    http.get.mockResolvedValueOnce({ items, total: 1000 })
      .mockResolvedValueOnce({ items: [], total: 1000 })

    expect(await list('news')).toEqual(items)
    expect(http.get).toHaveBeenCalledTimes(2)
  })

  it('reports the 100-page cap and returns the collected rows', async () => {
    const error = vi.spyOn(console, 'error').mockImplementation(() => {})
    http.get.mockImplementation((path, { page }) => Promise.resolve({ items: [{ id: String(page) }], total: 101 }))

    expect(await list('news')).toEqual(Array.from({ length: 100 }, (_, i) => ({ id: String(i + 1) })))
    expect(http.get).toHaveBeenCalledTimes(100)
    expect(http.get).toHaveBeenLastCalledWith('collections/news', {
      q: '', status: '', locale: undefined, onlyTranslated: false, page: 100, perPage: 200,
    })
    expect(error).toHaveBeenCalledExactlyOnceWith('Collections list exceeded 100 pages: news')
  })
})

import { beforeEach, describe, expect, it } from 'vitest'
import { createMock, parseQuery } from './handler.js'

let mock
const call = (method, path, { query = {}, body } = {}) => mock({ method, path, query, body })
const get = (path, query) => call('GET', path, { query })
beforeEach(() => { mock = createMock() })

describe('the language of the interface', () => {
  it('saves the choice of the person and answers the pair the server answers', () => {
    expect(mock({ method: 'GET', path: 'me', query: {} }).body).toMatchObject({ locale: 'en', localePreference: null })
    expect(mock({ method: 'PUT', path: 'me/locale', query: {}, body: { locale: 'kk' } }).body).toEqual({ ok: true, locale: 'kk', localePreference: 'kk' })
    expect(mock({ method: 'GET', path: 'me', query: {} }).body).toMatchObject({ locale: 'kk', localePreference: 'kk' })
    expect(mock({ method: 'PUT', path: 'me/locale', query: {}, body: { locale: null } }).body).toEqual({ ok: true, locale: 'en', localePreference: null })
  })

  it('refuses a language the panel does not have', () => {
    const answer = mock({ method: 'PUT', path: 'me/locale', query: {}, body: { locale: 'de' } })
    expect(answer.status).toBe(422)
    expect(answer.body.details.locale).toBeTruthy()
  })
})

describe('parseQuery reads the query string the way PHP does', () => {
  it('nested keys and lists', () => {
    const q = parseQuery(new URLSearchParams('q=a&filter[status]=draft&filter[date][from]=2026-01-01&tags[]=x&tags[]=y&envelope=1'))
    expect(q).toEqual({ q: 'a', filter: { status: 'draft', date: { from: '2026-01-01' } }, tags: ['x', 'y'], envelope: '1' })
  })
})

describe('what the admin asks before it starts', () => {
  it('the schema has what the screens read, and every key of the admin\'s shape', () => {
    const { status, body } = get('schema')
    expect(status).toBe(200)
    for (const k of ['mainLocale', 'design', 'features', 'sections', 'sectionGroups', 'pageFields', 'templates', 'layout', 'collections', 'menuAreas', 'settings', 'forms', 'permissionAreas', 'nav']) expect(body).toHaveProperty(k)
    expect(body.templates.map((t) => t.key)).toEqual(expect.arrayContaining(['home', 'default', 'blog_article']))
    expect(body.collections.some((c) => c.view === 'matrix' && c.matrix.words)).toBe(true)
  })

  it('me carries the CSRF token and the rights', () => {
    const { body } = get('me')
    expect(body.csrf).toBeTruthy()
    expect(body.perms.pages).toEqual(['view', 'edit', 'publish', 'delete'])
    expect(body.twoFactor).toEqual({ enabled: false, required: false, enrol: false })
  })

  it('languages, counters, prefs and the bell answer calmly', () => {
    expect(get('languages').body).toEqual({ main: 'ru', list: [{ code: 'ru', name: 'Русский' }] })
    expect(get('collections').body.map((c) => c.key)).toEqual(expect.arrayContaining(['news', 'plans']))
    expect(get('prefs/list.news').body).toEqual({ value: null })
    expect(call('PUT', 'prefs/list.news', { body: { value: { sort: 'title' } } }).status).toBe(200)
    expect(get('prefs/list.news').body).toEqual({ value: { sort: 'title' } })
    expect(get('notifications', { unread: '1' }).body).toEqual({ items: [], total: 0, unread: 0 })
    expect(get('dashboard').body).toHaveProperty('unpublished')
  })

  it('says plainly what the demo does not have', () => {
    const r = get('health')
    expect(r.status).toBe(404)
    expect(r.body.error).toMatch(/демо/i)
  })
})

describe('pages (written in memory)', () => {
  it('lists the pages with the fields of a row', () => {
    const rows = get('pages').body
    expect(rows.length).toBeGreaterThan(3)
    expect(rows[0]).toMatchObject({ template: 'home', url: '/', translated: true, locale: 'ru' })
    expect(rows[0].locales.ru).toBeTruthy()
  })

  it('a draft saved is a draft read again, and the page becomes "changed"', () => {
    const id = get('pages').body.find((p) => p.template === 'default').id
    const before = get(`pages/${id}/draft`).body
    expect(before.page.status).toBe('published')
    const saved = call('PUT', `pages/${id}/draft`, { body: { title: 'Новое имя', baseVersion: before.page.version } })
    expect(saved.status).toBe(200)
    expect(saved.body.title).toBe('Новое имя')
    const after = get(`pages/${id}/draft`).body
    expect(after.page.title).toBe('Новое имя')
    expect(after.page.version).not.toBe(before.page.version)
    expect(after.published.title).toBe(before.page.title)
    expect(get('pages').body.find((p) => p.id === id)).toMatchObject({ changed: true, title: 'Новое имя' })
  })

  it('publish makes the draft the live version, revert drops the draft', () => {
    const id = get('pages').body.find((p) => p.template === 'default').id
    call('PUT', `pages/${id}/draft`, { body: { title: 'А' } })
    call('POST', `pages/${id}/revert`)
    expect(get(`pages/${id}/draft`).body.page.title).not.toBe('А')
    call('PUT', `pages/${id}/draft`, { body: { title: 'Б' } })
    call('POST', `pages/${id}/publish`)
    const d = get(`pages/${id}/draft`).body
    expect(d.published.title).toBe('Б')
    expect(get('pages').body.find((p) => p.id === id).changed).toBe(false)
  })

  it('creates, moves and deletes pages', () => {
    const made = call('POST', 'pages', { body: { title: 'Контакты', url: '/contacts', parentId: null, template: 'default' } })
    expect(made.status).toBe(201)
    expect(made.body).toMatchObject({ title: 'Контакты', status: 'draft', url: '/contacts' })
    const id = made.body.id
    const home = get('pages').body.find((p) => p.template === 'home')
    expect(call('PUT', 'pages/tree', { body: [{ id, parentId: home.id, position: 0 }] }).body).toMatchObject({ ok: true })
    expect(get('pages').body.find((p) => p.id === id).parentId).toBe(home.id)
    expect(call('DELETE', `pages/${id}`).status).toBe(204)
    expect(get(`pages/${id}/draft`).status).toBe(404)
  })

  it('each mock starts from the same fixtures: a reload forgets what was written', () => {
    const id = get('pages').body.find((p) => p.template === 'default').id
    call('PUT', `pages/${id}/draft`, { body: { title: 'Чужое' } })
    expect(createMock()({ method: 'GET', path: `pages/${id}/draft`, query: {} }).body.page.title).not.toBe('Чужое')
  })
})

describe('collections', () => {
  it('queries with search, filter, sort and page', () => {
    const all = get('collections/news', { envelope: '1', perPage: '100' }).body
    expect(all.total).toBeGreaterThan(3)
    expect(all.items[0]).toMatchObject({ locale: 'ru', translated: true })
    const drafts = get('collections/news', { envelope: '1', filter: { status: 'draft' } }).body
    expect(drafts.items.every((r) => r.status === 'draft')).toBe(true)
    const found = get('collections/news', { envelope: '1', q: all.items[0].title.slice(0, 6) }).body
    expect(found.items.map((r) => r.id)).toContain(all.items[0].id)
    const sorted = get('collections/news', { envelope: '1', sort: 'title', perPage: '100' }).body.items.map((r) => r.title)
    expect(sorted).toEqual([...sorted].sort((a, b) => a.localeCompare(b, 'ru')))
    const page2 = get('collections/news', { envelope: '1', perPage: '2', page: '2' }).body
    expect(page2.items).toHaveLength(2)
    expect(page2.page).toBe(2)
  })

  it('without envelope gives all the rows', () => {
    expect(Array.isArray(get('collections/news').body)).toBe(true)
  })

  it('writes a record, publishes it, creates and deletes one', () => {
    const id = get('collections/news', { envelope: '1' }).body.items.find((r) => r.status === 'published').id
    const one = get(`collections/news/${id}`).body
    expect(one.item.id).toBe(id)
    expect(one.published).toBeTruthy()
    call('PUT', `collections/news/${id}`, { body: { title: 'Правка' } })
    expect(get(`collections/news/${id}`).body.item).toMatchObject({ title: 'Правка', changed: true })
    call('POST', `collections/news/${id}/publish`)
    expect(get(`collections/news/${id}`).body.item.changed).toBe(false)
    const made = call('POST', 'collections/news', { body: { title: 'Свежая' } })
    expect(made.status).toBe(201)
    expect(made.body.status).toBe('draft')
    expect(call('DELETE', `collections/news/${made.body.id}`).status).toBe(204)
    expect(get(`collections/news/${made.body.id}`).status).toBe(404)
  })

  it('the rail counts follow the writes', () => {
    const n = () => get('collections').body.find((c) => c.key === 'news').count
    const before = n()
    call('POST', 'collections/news', { body: { title: 'Ещё одна' } })
    expect(n()).toBe(before + 1)
  })

  it('the options matrix is one record with columns and rows', () => {
    const { item } = get('collections/plans/main').body
    expect(item.cols.length).toBeGreaterThan(1)
    expect(item.rows[0].vals).toHaveLength(item.cols.length)
    const cols = [...item.cols, { id: 'c9', name: 'Новый', price: '', note: '' }]
    call('PUT', 'collections/plans/main', { body: { cols, rows: item.rows.map((r) => ({ ...r, vals: [...r.vals, false] })) } })
    expect(get('collections/plans/main').body.item.cols).toHaveLength(item.cols.length + 1)
  })
})

describe('robots.txt and llms.txt', () => {
  const put = (body) => call('PUT', 'seo-files', { body })

  it('answers the standard texts at first', () => {
    const { robots, llms } = get('seo-files').body
    expect(robots).toMatchObject({ custom: false, file: false })
    expect(robots.text).toBe(robots.default)
    expect(llms.text).toBe(llms.default)
  })

  it('keeps an own text, leaves the other file alone, and takes the standard text (or null) back', () => {
    expect(put({ robots: 'Disallow: /\n' }).body.robots).toMatchObject({ text: 'Disallow: /\n', custom: true })
    expect(get('seo-files').body.llms.custom).toBe(false)
    expect(put({ robots: null }).body.robots.custom).toBe(false)
    put({ llms: 'x' })
    expect(put({ llms: get('seo-files').body.llms.default }).body.llms.custom).toBe(false)
  })

  it('refuses a text over 64 KB', () => {
    const answer = put({ robots: 'a'.repeat(64 * 1024 + 1) })
    expect(answer.status).toBe(422)
    expect(answer.body.details.robots).toBeTruthy()
    expect(get('seo-files').body.robots.custom).toBe(false)
  })
})

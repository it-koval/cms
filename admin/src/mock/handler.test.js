import { beforeEach, describe, expect, it } from 'vitest'
import { createMock, parseQuery } from './handler.js'
import { makeDb } from './fixtures.js'

let mock
const call = (method, path, { query = {}, body } = {}) => mock({ method, path, query, body })
const get = (path, query) => call('GET', path, { query })
beforeEach(() => { mock = createMock() })

describe('bot credentials in the demo', () => {
  const telegramToken = '123456789:' + 'x'.repeat(35)
  it('returns only metadata and includes bots in settings permissions', () => {
    expect(get('settings/bots')).toMatchObject({ status: 200, body: {
      telegram: { tokenSet: true, source: 'server', ready: true }, max: { tokenSet: true, source: 'server', ready: true },
    } })
    expect(get('schema').body.permissionAreas).toContainEqual(expect.objectContaining({ key: 'set:bots', actions: ['view', 'edit'] }))
    expect(get('me').body.perms['set:bots']).toEqual(['view', 'edit'])
  })

  it('overrides a server token, keeps it on empty input and restores fallback when cleared', () => {
    const write = (body) => call('PUT', 'settings/bots', { body })
    const saved = write({ telegram: { token: telegramToken } })
    expect(saved.body.telegram).toEqual({ tokenSet: true, source: 'admin', ready: true })
    expect(JSON.stringify(saved.body)).not.toContain(telegramToken)
    expect(write({ telegram: { token: '' } }).body.telegram.source).toBe('admin')
    expect(write({ telegram: { clear: true } }).body.telegram).toEqual({ tokenSet: true, source: 'server', ready: true })
  })

  it('clears a token without a server fallback and refreshes notification readiness', () => {
    const db = makeDb()
    db.bots.max = { token: 'demo-admin-secret', serverToken: '' }
    mock = createMock(db)
    expect(call('PUT', 'settings/bots', { body: { max: { clear: true } } }).body.max).toEqual({ tokenSet: false, source: null, ready: false })
    expect(get('settings').body.maxReady).toBe(false)
  })

  it('rejects malformed token payloads without modifying another bot', () => {
    expect(call('PUT', 'settings/bots', { body: { telegram: { token: telegramToken }, max: { token: 12 } } }).status).toBe(422)
    expect(get('settings/bots').body.telegram.source).toBe('server')
  })

  it('reports invalid token formats for both channels without persisting either', () => {
    const response = call('PUT', 'settings/bots', { body: { telegram: { token: 'bad' }, max: { token: 'bad token' } } })
    expect(response.status).toBe(422)
    expect(response.body.details).toEqual({ telegram: expect.any(String), max: expect.any(String) })
    expect(get('settings/bots').body).toMatchObject({ telegram: { source: 'server' }, max: { source: 'server' } })
  })

  it.each([' bad', 'bad ', 'bad\ttoken', 'bad\ntoken', 'bad\u0000token', 'bad\u007ftoken', 'x'.repeat(513)])('rejects a MAX token with whitespace, controls or excessive length without a partial Telegram write (%#)', (token) => {
    const response = call('PUT', 'settings/bots', { body: { telegram: { token: telegramToken }, max: { token } } })
    expect(response.status).toBe(422)
    expect(response.body.details.max).toBeTruthy()
    expect(get('settings/bots').body.telegram.source).toBe('server')
  })

  it.each(['bad', '1234:' + 'x'.repeat(35), '1'.repeat(21) + ':' + 'x'.repeat(35), '123456:' + 'x'.repeat(29), telegramToken + '\n', ' ' + telegramToken])('rejects invalid Telegram tokens without a partial MAX write (%#)', (token) => {
    const response = call('PUT', 'settings/bots', { body: { telegram: { token }, max: { token: 'max-valid-token' } } })
    expect(response.status).toBe(422)
    expect(response.body.details.telegram).toBeTruthy()
    expect(get('settings/bots').body.max.source).toBe('server')
  })

  it('preserves accepted tokens verbatim and accepts the MAX character limit', () => {
    const db = makeDb()
    mock = createMock(db)
    const maxToken = '\u00e9'.repeat(512)
    expect(call('PUT', 'settings/bots', { body: { telegram: { token: telegramToken }, max: { token: maxToken } } }).status).toBe(200)
    expect(db.bots.telegram.token).toBe(telegramToken)
    expect(db.bots.max.token).toBe(maxToken)
  })
})

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

describe('publishing the draft revision on screen', () => {
  it.each(['page', 'item'])('refuses a stale %s and preserves its published copy', (kind) => {
    const page = kind === 'page'
    const list = page ? 'pages' : 'collections/news'
    const response = get(list).body
    const row = (page ? response : response.items).find((r) => r.status === 'published')
    const record = `${list}/${row.id}`
    const draftPath = page ? `${record}/draft` : record
    const old = get(draftPath).body.published.title
    const fresh = call('PUT', draftPath, { body: { title: 'Other editor', baseVersion: row.version } }).body.version
    const refused = call('POST', `${record}/publish`, { body: { baseVersion: row.version } })
    expect(refused).toMatchObject({ status: 409, body: { details: { code: 'conflict', current: { version: fresh } } } })
    expect(get(draftPath).body.published.title).toBe(old)
    expect(call('POST', `${record}/publish`, { body: { baseVersion: fresh } }).status).toBe(200)
    expect(get(draftPath).body.published.title).toBe('Other editor')
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
    expect(get('languages').body).toEqual({ main: 'ru', list: [
      { code: 'ru', name: 'Русский' }, { code: 'kk', name: 'Қазақша' }, { code: 'tr', name: 'Türkçe' }, { code: 'zh', name: '中文' }, { code: 'en', name: 'English' },
    ] })
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

  it('always returns the B2 envelope, including without an envelope flag', () => {
    expect(get('collections/news').body).toMatchObject({ items: expect.any(Array), total: 5, page: 1, perPage: 25, facets: expect.any(Object), metrics: [] })
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

describe('multilingual content demo', () => {
  it('shows published, changed, draft and missing versions without replacing missing editor content', () => {
    const rows = get('pages', { locale: 'kk' }).body
    expect(rows.find((p) => p.id === 'p1')).toMatchObject({ locale: 'kk', translated: true, status: 'published', url: '/kk' })
    expect(rows.find((p) => p.id === 'p2')).toMatchObject({ translated: true, changed: true })
    expect(rows.find((p) => p.id === 'p3')).toMatchObject({ translated: true, status: 'draft' })
    expect(rows.find((p) => p.id === 'p4')).toMatchObject({ translated: false, status: 'missing', version: null })
    expect(get('pages/p4/draft', { locale: 'kk' }).body).toMatchObject({ page: null, published: null, translated: false, locale: 'kk', shell: { id: 'p4', template: 'catalog' } })
    for (const locale of ['tr', 'zh', 'en']) expect(get('pages', { locale }).body.some((p) => p.translated)).toBe(true)
  })

  it.each(['pages/p4', 'collections/news/n5', 'collections/plans/main', 'collections/company/company-profile'])('adds a copied translation, saves and publishes only that version, then deletes it: %s', (path) => {
    const page = path.startsWith('pages/')
    const editor = page ? `${path}/draft` : path
    const value = page ? 'page' : 'item'
    const original = get(editor).body[value]
    const added = call('POST', `${path}/translations`, { query: { locale: 'zh' }, body: { from: 'ru' } })
    expect(added).toMatchObject({ status: 201, body: { id: original.id, locale: 'zh', translated: true, status: 'draft', title: original.title } })
    if (page) expect(added.body.url).toBe('/zh/catalog')
    expect(call('POST', `${path}/translations`, { query: { locale: 'zh' }, body: { from: 'ru' } })).toMatchObject({ status: 409, body: { details: { code: 'translation_exists', locale: 'zh' } } })
    const saved = call('PUT', editor, { query: { locale: 'zh' }, body: { title: '中文标题', baseVersion: added.body.version } })
    expect(saved).toMatchObject({ status: 200, body: { locale: 'zh', title: '中文标题' } })
    expect(get(editor).body[value].title).toBe(original.title)
    expect(call('POST', `${path}/publish`, { query: { locale: 'zh' }, body: { baseVersion: added.body.version } }).status).toBe(409)
    expect(call('POST', `${path}/publish`, { query: { locale: 'zh' }, body: { baseVersion: saved.body.version } }).status).toBe(200)
    expect(get(editor, { locale: 'zh' }).body.published.title).toBe('中文标题')
    expect(call('DELETE', `${path}/translations/zh`)).toEqual({ status: 200, body: { ok: true } })
    expect(get(editor, { locale: 'zh' }).body).toMatchObject({ [value]: null, published: null, translated: false })
    expect(get(editor).body[value].title).toBe(original.title)
    const history = get(`history/${page ? 'page' : 'item'}/${original.id}`, { scope: 'all' }).body
    const deleted = history.items.find((r) => r.locale === 'zh')
    expect(deleted).toMatchObject({ action: 'delete_locale', current: false })
    expect(get(`history/revision/${deleted.id}`).body.canRestore).toBe(false)
    const previous = history.items.find((r) => r.locale === 'zh' && r.action !== 'delete_locale')
    expect(get(`history/revision/${previous.id}`).body.canRestore).toBe(false)
    expect(call('POST', `history/revision/${previous.id}/restore`)).toMatchObject({ status: 422, body: { details: { code: 'no_translation', locale: 'zh' } } })
  })

  it('keeps matrix columns shared only by identity and copies their content explicitly', () => {
    const path = 'collections/plans/main'
    expect(get(path, { locale: 'kk' }).body.item.cols[0].name).not.toBe(get(path).body.item.cols[0].name)
    expect(get(path, { locale: 'zh' }).body.item).toBeNull()
    expect(call('POST', `${path}/translations`, { query: { locale: 'zh' } }).body).toMatchObject({ cols: [], rows: [], locale: 'zh' })
    expect(call('POST', 'collections/plans', { body: {} }).status).toBe(409)
    expect(call('POST', 'collections/company', { body: { title: 'Duplicate' } }).status).toBe(409)
  })

  it('rejects unknown languages, absent source versions and unintentional main-language deletion', () => {
    for (const path of ['pages/p4', 'collections/news/n5']) {
      expect(call('POST', `${path}/translations`, { body: { locale: 'zh' } }).status).toBe(422)
      expect(call('POST', `${path}/translations`, { query: { locale: 'de' } }).status).toBe(422)
      expect(call('POST', `${path}/translations`, { query: { locale: 'zh' }, body: { from: 'zh' } }).status).toBe(422)
      expect(call('POST', `${path}/translations`, { query: { locale: 'zh' }, body: { from: 'tr' } })).toMatchObject({ status: 422, body: { details: { code: 'translation_source_missing' } } })
      expect(call('DELETE', `${path}/translations/ru`).status).toBe(409)
      expect(call('DELETE', `${path}/translations/zh`).status).toBe(404)
    }
    expect(get('pages', { locale: 'de' }).status).toBe(422)
    expect(get('collections/news', { locale: 'de' }).status).toBe(422)
  })

  it('filters status and onlyTranslated before pagination; published excludes changed drafts', () => {
    const query = { locale: 'kk', perPage: '1', page: '2' }
    const translated = get('collections/news', { ...query, onlyTranslated: 'true' }).body
    expect(translated.total).toBe(3)
    expect(translated.items).toHaveLength(1)
    expect(translated.items[0].translated).toBe(true)
    const ids = (extra) => get('collections/news', { locale: 'kk', ...extra }).body.items.map((r) => r.id)
    expect(ids({ status: 'published' })).toEqual(['n1'])
    expect(ids({ status: 'draft' }).sort()).toEqual(['n2', 'n3'])
    expect(ids({ status: 'missing' }).sort()).toEqual(['n4', 'n5'])
    expect(get('collections/news', { locale: 'kk', status: 'missing', onlyTranslated: '1' }).body.total).toBe(0)
    expect(get('collections/news', { locale: 'kk', onlyTranslated: 'false' }).body.total).toBe(5)
    expect(get('collections/news', { status: 'invalid' }).status).toBe(422)
    expect(get('collections/news', { perPage: '999' }).body.perPage).toBe(200)
    expect(get('collections/news', { page: '99' }).body.items).toEqual([])
    expect(get('collections/news', { locale: 'kk', filter: { status: 'published' } }).body.items.map((r) => r.id).sort()).toEqual(['n1', 'n2'])
    expect(get('collections/news', { locale: 'kk' }).body.facets.status).toEqual({ published: 1, draft: 2, missing: 2 })
  })

  it('counts enabled language versions and carries the language in Today links', () => {
    const news = get('collections').body.find((r) => r.key === 'news')
    expect(news.locales).toEqual({ ru: { count: 5, unpublished: 1 }, kk: { count: 3, unpublished: 2 }, tr: { count: 1, unpublished: 0 }, zh: { count: 0, unpublished: 0 }, en: { count: 1, unpublished: 0 } })
    expect(news.unpublished).toBe(3)
    expect(get('collections', { locale: 'kk' }).body.find((r) => r.key === 'news').unpublished).toBe(2)
    expect(get('collections').body.find((r) => r.key === 'plans').count).toBe(3)
    const today = get('dashboard').body
    expect(today.languages).toEqual(get('languages').body)
    expect(today.locales.kk).toEqual({ pages: { count: 3, unpublished: 2 }, collections: { count: 5, unpublished: 3 } })
    expect(today.locales.zh).toEqual({ pages: { count: 1, unpublished: 0 }, collections: { count: 0, unpublished: 0 } })
    expect(today.unpublished.some((r) => r.locale === 'kk' && r.to.includes('?lang=kk'))).toBe(true)
    expect(today.recent.every((r) => r.locale && r.to.includes(`?lang=${r.locale}`))).toBe(true)
  })

  it('keeps settings texts and menu items in their own language, with no fallback for ZH', () => {
    expect(get('settings', { locale: 'kk' }).body.siteTitle).toBe('Демо компаниясы')
    expect(get('settings', { locale: 'zh' }).body.siteTitle).toBe('')
    expect(get('settings', { locale: 'kk' }).body.phone).toBe(get('settings').body.phone)
    call('PATCH', 'settings', { query: { locale: 'kk' }, body: { siteTitle: 'Жаңа атау' } })
    expect(get('settings').body.siteTitle).toBe('Демо-компания')
    expect(get('menus', { locale: 'kk' }).body.header[0].label).toBe('Басты бет')
    expect(get('menus').body.header[0].link.page).toBe('/')
    expect(get('menus', { locale: 'kk' }).body.header[0].link.page).toBe('/kk')
    expect(get('menu-areas').body).toEqual([{ key: 'header', name: 'Верхнее меню', depth: 2, snippet: expect.any(String), usedIn: [], usedInAll: false, counts: { ru: 1, kk: 1 } }])
    expect(get('menus', { locale: 'zh' }).body.header).toEqual([])
    call('PUT', 'menus', { query: { locale: 'zh' }, body: { header: [{ _id: 'zh1', label: '首页', link: { kind: 'page', page: '/zh' } }] } })
    expect(get('menus', { locale: 'zh' }).body.header[0].label).toBe('首页')
    expect(get('menu-areas').body[0].counts.zh).toBe(1)
    expect(get('menus', { locale: 'kk' }).body.header[0].label).toBe('Басты бет')
  })

  it('returns mixed-language history, paginates it and marks the latest entry of each language', () => {
    const path = 'history/page/p1'
    const all = get(path, { scope: 'all', locale: 'zh' }).body
    expect(all).toMatchObject({ items: expect.any(Array), total: expect.any(Number), limit: 50, offset: 0, keep: expect.any(Number), days: expect.any(Number) })
    expect(new Set(all.items.map((r) => r.locale)).size).toBeGreaterThan(1)
    expect(all.items.filter((r) => r.current && r.locale === 'kk')).toHaveLength(1)
    expect(all.items.filter((r) => r.locale === 'kk').length).toBeGreaterThan(1)
    expect(get(path, { locale: 'kk' }).body.items.every((r) => r.locale === 'kk')).toBe(true)
    expect(get(path, { locale: 'kk', scope: 'other' }).body.items).toEqual(get(path, { locale: 'kk' }).body.items)
    expect(get(path, { scope: 'all', limit: '1', offset: '1' }).body).toMatchObject({ items: [all.items[1]], total: all.total, limit: 1, offset: 1 })
    call('PUT', 'pages/p1/draft', { query: { locale: 'kk' }, body: { title: 'Өзгертілді' } })
    const updated = get(path, { scope: 'all' }).body.items
    expect(updated[0]).toMatchObject({ locale: 'kk', title: 'Өзгертілді', current: true })
    expect(updated.filter((r) => r.locale === 'kk' && r.current)).toHaveLength(1)
    expect(updated.filter((r) => r.locale === 'ru' && r.current)).toHaveLength(1)
  })

  it('restores a KK history entry into the KK draft and keeps its published copy', () => {
    const entry = get('history/page/p1', { locale: 'kk' }).body.items[0]
    const live = get('pages/p1/draft', { locale: 'kk' }).body.published
    call('PUT', 'pages/p1/draft', { query: { locale: 'kk' }, body: { title: 'Жаңа атау' } })
    expect(call('POST', `history/revision/${entry.id}/restore`)).toMatchObject({ status: 200, body: { ok: true, kind: 'page', id: 'p1', locale: 'kk', restored: 'version' } })
    expect(get('pages/p1/draft', { locale: 'kk' }).body.page.title).toBe(entry.title)
    expect(get('pages/p1/draft', { locale: 'kk' }).body.published).toEqual(live)
    expect(get('pages/p1/draft').body.page.title).toBe('Главная')
  })

  it('keeps the object permission boundary when history is requested across all languages', () => {
    const db = makeDb()
    db.user.perms.pages = []
    mock = createMock(db)
    expect(get('history/page/p1', { scope: 'all' }).status).toBe(403)
  })

  it.each(['settings/general', 'menu/header'])('keeps all-language history and restores the selected language for %s', (resource) => {
    const [kind] = resource.split('/')
    const path = kind === 'settings' ? 'settings' : 'menus'
    const original = get(path, { locale: 'kk' }).body
    const entry = get(`history/${resource}`, { scope: 'all' }).body.items.find((r) => r.locale === 'kk')
    const body = kind === 'settings' ? { siteTitle: 'Жаңа атау' } : { header: [] }
    call(kind === 'settings' ? 'PATCH' : 'PUT', path, { query: { locale: 'kk' }, body })
    expect(call('POST', `history/revision/${entry.id}/restore`)).toMatchObject({ status: 200, body: { ok: true, locale: 'kk', kind, restored: 'version' } })
    expect(get(path, { locale: 'kk' }).body).toEqual(original)
    expect(get(`history/${resource}`, { locale: 'kk' }).body.items.every((r) => r.locale === 'kk')).toBe(true)
  })

  it.each(['pages/p3', 'collections/news/n3'])('keeps submitted KK content in the publisher queue and removes it after publish: %s', (path) => {
    call('POST', `${path}/submit`, { query: { locale: 'kk' } })
    const id = path.split('/').at(-1)
    const row = get('dashboard').body.awaiting.find((r) => r.id === id && r.locale === 'kk')
    expect(row).toMatchObject({ locale: 'kk', by: expect.any(String), at: expect.any(String), to: `${path.startsWith('pages') ? '/pages' : '/collections/news'}/${id}?lang=kk` })
    call('POST', `${path}/publish`, { query: { locale: 'kk' } })
    expect(get('dashboard').body.awaiting.some((r) => r.id === id && r.locale === 'kk')).toBe(false)
  })

  it('republishes the saved ZH live snapshot after unpublishing without publishing later edits', () => {
    const original = get('pages/p1/draft', { locale: 'zh' }).body.published
    call('PUT', 'pages/p1/draft', { query: { locale: 'zh' }, body: { title: 'Later draft' } })
    expect(call('POST', 'pages/p1/unpublish', { query: { locale: 'zh' } }).status).toBe(200)
    expect(get('pages/p1/draft', { locale: 'zh' }).body.published).toBeNull()
    expect(call('POST', 'pages/p1/republish', { query: { locale: 'zh' } }).status).toBe(200)
    expect(get('pages/p1/draft', { locale: 'zh' }).body).toMatchObject({ published: original, page: { title: 'Later draft', changed: true } })
  })

  it('links the pending matrix version directly to the matrix screen', () => {
    const item = get('collections/plans/main', { locale: 'kk' }).body.item
    call('PUT', 'collections/plans/main', { query: { locale: 'kk' }, body: { cols: [...item.cols, { id: 'c9', name: 'New' }] } })
    expect(get('dashboard').body.unpublished.find((r) => r.collection === 'plans' && r.locale === 'kk').to).toBe('/collections/plans?lang=kk')
  })

  it.each(['pages/p3', 'collections/news/n3'])('puts whole objects in the trash, never translation deletions, and restores them as drafts: %s', (path) => {
    const id = path.split('/').at(-1)
    const kind = path.startsWith('pages') ? 'page' : 'item'
    call('DELETE', `${path}/translations/kk`)
    expect(get('history/trash').body).toEqual({ items: [], total: 0 })
    call('DELETE', path)
    const trash = get('history/trash', { kind }).body
    expect(trash).toMatchObject({ total: 1, items: [{ kind, resourceId: id, locales: ['ru'], wasLive: true, canRestore: true, restorable: true }] })
    expect(get(`history/${kind}/${id}`, { scope: 'all' }).body.items.every((r) => !r.current)).toBe(true)
    const deleted = trash.items[0]
    expect(call('POST', `history/revision/${deleted.id}/restore`)).toMatchObject({ status: 200, body: { ok: true, restored: 'trash', id } })
    expect(get('history/trash').body).toEqual({ items: [], total: 0 })
    const editor = path.startsWith('pages') ? `${path}/draft` : path
    expect(get(editor).body).toMatchObject({ translated: true, published: null, [kind === 'page' ? 'page' : 'item']: { id, status: 'draft' } })
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

describe('Яндекс Вебмастер', () => {
  const post = (path, body) => call('POST', `yandex-webmaster/${path}`, { body })

  it('starts not connected and refuses a bad token without connecting', () => {
    expect(get('yandex-webmaster').body).toMatchObject({ state: 'not_connected', canManage: true, summary: null, queries: null })
    expect(post('connect', { token: '' }).status).toBe(422)
    expect(post('connect', { token: 'bad' }).status).toBe(422)
    expect(post('connect', { token: 'down' }).status).toBe(502)
    expect(get('yandex-webmaster').body.state).toBe('not_connected')
  })

  it('connects into verifying, a refresh finishes the check, a disconnect forgets everything', () => {
    expect(post('connect', { token: 'y0_secret' }).body).toMatchObject({ state: 'verifying', summary: null })
    const ready = post('refresh').body
    expect(ready.state).toBe('ready')
    expect(ready.queries.rows.length).toBeLessThanOrEqual(10)
    expect(JSON.stringify(ready)).not.toContain('y0_secret')
    expect(call('DELETE', 'yandex-webmaster').body.state).toBe('not_connected')
  })

  it('reaches each state by a demo token', () => {
    expect(post('connect', { token: 'demo:verification_failed' }).body.verification.failReason).toBe('META_TAG_NOT_FOUND')
    expect(post('connect', { token: 'demo:ready+viewer+expiring+error' }).body).toMatchObject({ state: 'ready', canManage: false, tokenExpiresSoon: true })
    expect(post('connect', { token: 'demo:ready+empty' }).body).toMatchObject({ state: 'ready', summary: null, queries: null })
  })
})

describe('requests and their delivery to the messengers', () => {
  it('slices filtered requests newest first and keeps the filtered total on later pages', () => {
    const db = makeDb()
    const row = db.requests[0]
    db.requests = [3, 1, 5, 2, 4].map((id) => ({ ...row, id, locale: id > 1 ? 'kk' : 'ru', dt: row.dt }))
    mock = createMock(db)
    const first = get('requests', { locale: 'kk', perPage: '2' }).body
    const second = get('requests', { locale: 'kk', perPage: '2', page: '2' }).body
    expect(first).toMatchObject({ total: 4, page: 1, perPage: 2 })
    expect(first.items.map((item) => item.id)).toEqual([5, 4])
    expect(second.items.map((item) => item.id)).toEqual([3, 2])
    expect(get('media', { page: '2' }).body).toEqual({ items: [], total: 0, page: 2, perPage: 50, nextCursor: null, previousCursor: null })
    db.requests.push({ ...row, id: 6, locale: 'kk' })
    expect(get('requests', { locale: 'kk', perPage: '2', page: '2', cursor: first.nextCursor }).body.items.map((item) => item.id)).toEqual([3, 2])
  })

  const failedOne = () => get('requests').body.items.find((r) => r.st === 'failed')

  it('matches phone digits across stored punctuation, handles missing numbers, and keeps text search case-insensitive', () => {
    const db = makeDb()
    const row = { ...db.requests[0], phone: '+7 (900) 111-22-33', name: 'Visitor', city: 'Astana', form: 'CallBack' }
    const missing = { ...db.requests[0], id: 99, phone: null, name: null, city: null, form: 'Order' }
    db.requests = [row, missing]
    mock = createMock(db)
    const ids = (q) => get('requests', { q }).body.items.map((r) => r.id)
    for (const q of ['79001112233', '+7 900 111-22-33', '11122', ' VISITOR ', 'ASTANA', 'callback']) expect(ids(q)).toEqual([row.id])
    expect(ids('order')).toEqual([missing.id])
    expect(ids('999')).toEqual([])
    expect(ids(null)).toEqual([missing.id, row.id])
  })

  it('every request tells the state of each channel, and the aggregate is derived from them', () => {
    const r = failedOne()
    expect(r.channels).toEqual([{ channel: 'telegram', state: 'sent', error: null }, { channel: 'max', state: 'failed', error: 'chat not found' }])
    expect(r).toMatchObject({ delivery: 'failed', deliveryError: 'MAX: chat not found' })
    expect(get('requests/counts').body.failed).toBe(1)
    expect(get('requests', { status: 'failed' }).body.items.map((x) => x.id)).toEqual([r.id])
  })

  it('a resend to one channel changes only that channel', () => {
    const r = failedOne()
    const sent = call('POST', `requests/${r.id}/resend`, { body: { channel: 'telegram' } }).body
    expect(sent.channels.find((c) => c.channel === 'max').state).toBe('failed')
    const done = call('POST', `requests/${r.id}/resend`, { body: { channel: 'max' } }).body
    expect(done).toMatchObject({ ok: true, delivery: 'sent', st: 'delivered' })
    expect(get('requests/counts').body.failed).toBe(0)
  })

  it('a resend without a channel goes to the failed ones', () => {
    const r = failedOne()
    expect(call('POST', `requests/${r.id}/resend`).body.channels.map((c) => c.state)).toEqual(['sent', 'sent'])
  })
})

describe('notification settings', () => {
  it('carry the MAX keys and never take the read-only answers of the server from a PATCH', () => {
    expect(get('settings').body).toMatchObject({ maxOn: true, maxReady: true, tgReady: true, maxFallback: false })
    const saved = call('PATCH', 'settings', { body: { maxOn: false, maxRoutes: { '*': '-5' }, maxReady: false, tgReady: false } }).body
    expect(saved).toMatchObject({ maxOn: false, maxRoutes: { '*': '-5' }, maxReady: true, tgReady: true })
  })

  it('a MAX test answers like the server: the chat, or a 502 when the messenger refuses', () => {
    expect(call('POST', 'settings/max-test', { body: { form: '*', chatId: '-5' } })).toEqual({ status: 200, body: { ok: true, chat: '*', sent: 1 } })
    expect(call('POST', 'settings/max-test', { body: { chat: 'Звонок', form: 'call', chatId: '-5, -6' } })).toEqual({ status: 200, body: { ok: true, chat: 'Звонок', sent: 2 } })
    expect(call('POST', 'settings/max-test', { body: { chatId: '-5' } }).body.chat).toBe('other')
    expect(call('POST', 'settings/max-test', { body: { chatId: '0' } }).status).toBe(502)
  })
})

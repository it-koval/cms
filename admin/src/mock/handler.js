// The server of the mock mode (VITE_MOCK=1): `createMock()` answers { status, body } to { method, path, query, body } for the calls the screens make to /admin/api, from the data of
// fixtures.js. Everything written stays in memory, in the closure: a reload of the page starts again from the fixtures. Pure code, no DOM: install.js puts it behind `fetch`.
//
// What is answered: schema, me, languages, dashboard, collections (the rail), prefs, the bell, pages (list, draft, save, create, update, delete, publish, revert, unpublish, submit, return, tree)
// and records of collections (query, get, save, create, delete, publish, revert, submit, return, order; the options matrix is one record 'main'), robots.txt / llms.txt (seo-files). Anything else is a 404 that says it is the demo.
import { makeDb } from './fixtures.js'

const copy = (v) => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)))
const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b)
const ok = (body) => ({ status: 200, body })
const none = () => ({ status: 204, body: null })
const fail = (status, error, details = null) => ({ status, body: details ? { error, details } : { error } })
const UI_LOCALES = ['en', 'ru', 'kk']
const UI_DEFAULT = 'en'
const NOT_HERE = () => fail(404, 'Этого в демо-режиме нет: он показывает только страницы и коллекции')
const NO_PAGE = () => fail(404, 'Такой страницы нет')
const NO_RECORD = () => fail(404, 'Такой записи нет')

/** `a=1&filter[status]=draft&filter[date][from]=x&tags[]=a&tags[]=b` -> { a: '1', filter: { status: 'draft', date: { from: 'x' } }, tags: ['a', 'b'] }, the way PHP reads it. */
export function parseQuery(params) {
  const out = {}
  params.forEach((value, key) => {
    const parts = [...key.matchAll(/([^[\]]+)|\[(\])/g)].map((m) => (m[2] ? '' : m[1]))
    let at = out
    parts.forEach((part, i) => {
      const last = i === parts.length - 1
      if (part === '') {
        if (!Array.isArray(at)) return
        at.push(value)
        return
      }
      if (last) at[part] = value
      else {
        if (at[part] === undefined) at[part] = parts[i + 1] === '' ? [] : {}
        at = at[part]
      }
    })
  })
  return out
}

const SEO_FILES_MAX = 64 * 1024
const SEO_FILES_DEFAULT = {
  robots: 'User-agent: *\nDisallow: /admin/\nDisallow: /request/\nDisallow: /mcp\n\nSitemap: https://example.com/sitemap.xml\n',
  llms: '# Example\n\n> A demo site of ITK CMS.\n\n## Pages\n\n- [Home](https://example.com/): The main page\n',
}

const PAGE_KEYS = ['title', 'url', 'seo', 'sections', 'fields', 'parentId', 'template']
const PAGE_EDIT = ['title', 'url', 'seo', 'fields', 'parentId', 'template']
const RECORD_META = ['id', 'status', 'updatedAt', 'version', 'awaiting', 'returned', 'published']
const LOCALE = 'ru'

export function createMock(db = makeDb()) {
  const now = () => new Date().toISOString()
  const bump = (rec) => { rec.version = `v${++db.seq}`; rec.updatedAt = now() }
  const by = () => ({ by: db.user.name, at: now() })

  /* ---------- pages ---------- */
  const pageDiff = (p) => (p.published ? PAGE_KEYS.filter((k) => !eq(p[k], p.published[k])) : [])
  const pageSummary = (p) => ({
    status: p.published ? 'published' : 'draft', changed: pageDiff(p).length > 0, changes: pageDiff(p).length, published: !!p.published, title: p.title, url: p.url,
    updatedAt: p.updatedAt, migrated: !!p.migrated, sectionsCount: p.sections.length, awaiting: p.awaiting,
  })
  const flatPage = (p) => {
    const { published, pos, ...draft } = p
    return { ...copy(draft), locale: LOCALE, translated: true, ...pageSummary(p), locales: { [LOCALE]: pageSummary(p) } }
  }
  const snapshot = (p) => (p.published ? { ...copy(p.published), id: p.id, locale: LOCALE, translated: true, status: 'published', version: p.version, updatedAt: p.updatedAt } : null)
  const findPage = (id) => db.pages.find((p) => p.id === id)
  const inOrder = () => [...db.pages].sort((a, b) => (a.pos ?? 0) - (b.pos ?? 0))
  db.pages.forEach((p, i) => { if (p.pos === undefined) p.pos = i * 10 })
  const withKids = (id) => [id, ...db.pages.filter((p) => p.parentId === id).flatMap((p) => withKids(p.id))]

  function pages(method, rest, query, body) {
    const [id, action, extra] = rest
    if (!id) {
      if (method === 'GET') {
        return ok(inOrder().filter((p) => (!query.template || p.template === query.template) && (query.parentId === undefined || (p.parentId || '') === query.parentId)).map(flatPage))
      }
      if (method === 'POST') {
        const made = {
          id: body.id || `p${++db.seq}`, parentId: body.parentId ?? null, template: body.template || 'default', title: body.title || 'Без названия', url: body.url || '/', seo: { title: '', description: '' },
          fields: copy(body.fields) || {}, sections: copy(body.sections) || [], updatedAt: now(), version: `v${++db.seq}`, migrated: false, awaiting: null, returned: null, published: null, pos: db.pages.length * 10 + 5,
        }
        db.pages.push(made)
        return { status: 201, body: flatPage(made) }
      }
      return NOT_HERE()
    }
    if (id === 'tree' && method === 'PUT') {
      ;(Array.isArray(body) ? body : []).forEach((n) => {
        const p = findPage(n.id)
        if (!p) return
        p.parentId = n.parentId ?? null
        p.pos = Number(n.position) || 0
        bump(p)
      })
      return ok({ ok: true, moved: Array.isArray(body) ? body.length : 0 })
    }
    const p = findPage(id)
    if (!p) return NO_PAGE()
    if (!action) {
      if (method === 'DELETE') {
        const gone = new Set(withKids(id))
        db.pages = db.pages.filter((x) => !gone.has(x.id))
        return none()
      }
      if (method === 'PATCH') {
        PAGE_EDIT.forEach((k) => { if (body[k] !== undefined) p[k] = copy(body[k]) })
        bump(p)
        return ok(flatPage(p))
      }
      return NOT_HERE()
    }
    if (action === 'draft') {
      if (method === 'GET') return ok({ page: flatPage(p), published: snapshot(p), changes: [], locale: LOCALE, translated: true, locales: { [LOCALE]: pageSummary(p) }, shell: { id: p.id, parentId: p.parentId, template: p.template } })
      if (method === 'PUT') {
        if (body.baseVersion && body.baseVersion !== p.version) return fail(409, 'Страницу изменили, пока она была открыта', { code: 'conflict', current: { version: p.version, ...by(), via: 'admin', viaName: '' } })
        PAGE_KEYS.forEach((k) => { if (body[k] !== undefined) p[k] = copy(body[k]) })
        bump(p)
        return ok(flatPage(p))
      }
    }
    if (method === 'POST' && extra === undefined) {
      if (action === 'publish') { p.published = copy(Object.fromEntries(PAGE_KEYS.map((k) => [k, p[k]]))); p.awaiting = null; p.returned = null; bump(p); return ok(flatPage(p)) }
      if (action === 'revert') { if (p.published) PAGE_KEYS.forEach((k) => { p[k] = copy(p.published[k]) }); bump(p); return ok(flatPage(p)) }
      if (action === 'unpublish') { p.published = null; bump(p); return ok(flatPage(p)) }
      if (action === 'submit') { p.awaiting = by(); bump(p); return ok(flatPage(p)) }
      if (action === 'return') { p.awaiting = null; bump(p); return ok(flatPage(p)) }
    }
    return NOT_HERE()
  }

  /* ---------- collections ---------- */
  const cfgOf = (key) => db.schema.collections.find((c) => c.key === key)
  const dataKeys = (r) => Object.keys(r).filter((k) => !RECORD_META.includes(k))
  const recDiff = (r) => (r.published ? dataKeys(r).filter((k) => !eq(r[k], r.published[k])) : [])
  const recSummary = (cfg, r) => ({
    status: r.published ? 'published' : 'draft', changed: recDiff(r).length > 0, changes: recDiff(r).length, title: r[cfg.titleField] ?? '', updatedAt: r.updatedAt, awaiting: r.awaiting,
  })
  const flatRecord = (cfg, r) => {
    const { published, ...rest } = r
    const s = recSummary(cfg, r)
    return { ...copy(rest), locale: LOCALE, translated: true, ...s, locales: { [LOCALE]: s } }
  }
  const publishedRecord = (r) => (r.published ? { ...copy(r.published), id: r.id, locale: LOCALE, translated: true, status: 'published', version: r.version, updatedAt: r.updatedAt } : null)
  const asRows = (cfg, filtered) => filtered.map((r) => flatRecord(cfg, r))

  function compareBy(sort) {
    const keys = String(sort || '').split(',').filter(Boolean).map((k) => (k.startsWith('-') ? [k.slice(1), -1] : [k, 1]))
    return (a, b) => {
      for (const [k, dir] of keys) {
        const x = a[k] ?? ''
        const y = b[k] ?? ''
        const c = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), 'ru')
        if (c !== 0) return c * dir
      }
      return 0
    }
  }

  function collections(method, rest, query, body) {
    const [key, id, action] = rest
    if (!key) return method === 'GET' ? ok(railList()) : NOT_HERE()
    const cfg = cfgOf(key)
    const store = db.collections[key]
    if (!cfg || !store) return fail(404, 'Такой коллекции нет')
    const recs = store.records
    const find = (rid) => recs.find((r) => r.id === rid)
    if (!id) {
      if (method === 'GET') {
        const q = String(query.q || '').trim().toLowerCase()
        const status = (query.filter && query.filter.status) || query.status || ''
        let rows = recs.filter((r) => {
          if (q && !(cfg.searchKeys || [cfg.titleField]).some((k) => String(r[k] ?? '').toLowerCase().includes(q))) return false
          if (status === 'published') return !!r.published
          if (status === 'draft') return !r.published || recDiff(r).length > 0
          if (status === 'missing') return false
          return true
        })
        if (!query.envelope) return ok(asRows(cfg, rows))
        rows = asRows(cfg, rows).sort(compareBy(query.sort || cfg.defaultSort))
        const perPage = Math.max(1, Number(query.perPage) || cfg.perPage || 25)
        const page = Math.max(1, Number(query.page) || 1)
        return ok({ items: rows.slice((page - 1) * perPage, page * perPage), total: rows.length, page, perPage, facets: {}, metrics: [] })
      }
      if (method === 'POST') {
        const made = { id: `n${++db.seq}`, status: 'draft', updatedAt: now(), version: `v${++db.seq}`, awaiting: null, returned: null, published: null, ...copy(body) }
        recs.unshift(made)
        return { status: 201, body: flatRecord(cfg, made) }
      }
      return NOT_HERE()
    }
    if (id === 'order' && method === 'PUT') return ok({ ok: true })
    const r = find(id)
    if (!r) return NO_RECORD()
    if (!action) {
      if (method === 'GET') return ok({ item: flatRecord(cfg, r), published: publishedRecord(r), changes: [], locale: LOCALE, translated: true, locales: { [LOCALE]: recSummary(cfg, r) } })
      if (method === 'PUT') {
        if (body.baseVersion && body.baseVersion !== r.version) return fail(409, 'Запись изменили, пока она была открыта', { code: 'conflict', current: { version: r.version, ...by(), via: 'admin', viaName: '' } })
        Object.entries(body).forEach(([k, v]) => { if (k !== 'baseVersion' && !RECORD_META.includes(k)) r[k] = copy(v) })
        bump(r)
        return ok(flatRecord(cfg, r))
      }
      if (method === 'DELETE') { store.records = recs.filter((x) => x.id !== id); return none() }
      return NOT_HERE()
    }
    if (method === 'POST') {
      if (action === 'publish') { r.published = copy(Object.fromEntries(dataKeys(r).map((k) => [k, r[k]]))); r.awaiting = null; r.returned = null; bump(r); return ok(flatRecord(cfg, r)) }
      if (action === 'revert') { if (r.published) dataKeys(r).forEach((k) => { r[k] = copy(r.published[k]) }); bump(r); return ok(flatRecord(cfg, r)) }
      if (action === 'submit') { r.awaiting = by(); bump(r); return ok(flatRecord(cfg, r)) }
      if (action === 'return') { r.awaiting = null; bump(r); return ok(flatRecord(cfg, r)) }
    }
    return NOT_HERE()
  }

  const railList = () => db.schema.collections.map((cfg) => {
    const rs = db.collections[cfg.key].records
    const open = rs.filter((r) => !r.published || recDiff(r).length > 0).length
    return { key: cfg.key, title: cfg.title, count: rs.length, unpublished: open, locales: { [LOCALE]: { count: rs.length, unpublished: open } } }
  })

  /* ---------- «Сегодня» ---------- */
  function dashboard() {
    const unpublished = [
      ...db.pages.filter((p) => !p.published || pageDiff(p).length).map((p) => ({
        kind: 'page', id: p.id, locale: LOCALE, template: p.template, title: p.title, pageKind: 'Страница', to: `/pages/${p.id}`, changes: pageDiff(p).length, draft: !p.published, updatedAt: p.updatedAt, migrated: !!p.migrated, awaiting: p.awaiting,
      })),
      ...db.schema.collections.flatMap((cfg) => db.collections[cfg.key].records.filter((r) => !r.published || recDiff(r).length).map((r) => ({
        kind: 'item', collection: cfg.key, id: r.id, locale: LOCALE, title: String(r[cfg.titleField] || cfg.title), pageKind: cfg.title, to: `/collections/${cfg.key}/${r.id}`, changes: recDiff(r).length, draft: !r.published, updatedAt: r.updatedAt, migrated: false, awaiting: r.awaiting,
      }))),
    ]
    const recent = db.pages.filter((p) => p.published).slice(0, 4).map((p) => ({ label: p.title, to: `/pages/${p.id}`, at: p.updatedAt, locale: LOCALE }))
    return { newRequests: [], failedCount: 0, unpublished, awaiting: [], proposals: [], recent, languages: { main: LOCALE, list: [{ code: LOCALE, name: 'Русский' }] } }
  }

  /** «Мой профиль → Язык интерфейса»: PUT me/locale { locale }, as the server answers it. The choice itself outlives a reload in localStorage (i18n/index.js), this memory does not. */
  function setUiLocale(method, { locale }) {
    if (method !== 'PUT') return NOT_HERE()
    if (locale !== null && !UI_LOCALES.includes(locale)) return fail(422, 'Такого языка в панели нет', { locale: 'Выберите язык из списка' })
    db.user.locale = locale || UI_DEFAULT
    db.user.localePreference = locale
    return ok({ ok: true, locale: db.user.locale, localePreference: locale })
  }

  /* ---------- robots.txt and llms.txt: { robots|llms: { text, default, custom, file } }; an own text is kept, the standard one (or '' / null) clears it ---------- */
  const seoOwn = { robots: null, llms: null }
  const seoFiles = () => Object.fromEntries(Object.keys(SEO_FILES_DEFAULT).map((k) => [k, {
    text: seoOwn[k] ?? SEO_FILES_DEFAULT[k], default: SEO_FILES_DEFAULT[k], custom: seoOwn[k] !== null, file: false,
  }]))
  function seoFilesCall(method, body) {
    if (method === 'GET') return ok(seoFiles())
    if (method !== 'PUT') return NOT_HERE()
    const details = {}
    Object.keys(SEO_FILES_DEFAULT).forEach((k) => {
      if (!(k in body)) return
      const v = body[k]
      if (typeof v === 'string' && v.length > SEO_FILES_MAX) details[k] = 'The text is too long: 64 KB at most'
      else seoOwn[k] = v === null || v === '' || v === SEO_FILES_DEFAULT[k] ? null : String(v)
    })
    return Object.keys(details).length ? fail(422, 'The text is not saved', details) : ok(seoFiles())
  }

  /** The call. `path` is what comes after the api base («pages/p1/draft»), `query` is parseQuery(), `body` is the parsed JSON (or undefined). */
  return function handle({ method = 'GET', path = '', query = {}, body }) {
    const rest = String(path).split('/').filter(Boolean).map(decodeURIComponent)
    const [head, ...tail] = rest
    const m = method.toUpperCase()
    const data = body && typeof body === 'object' ? body : {}
    switch (head) {
      case 'schema': return ok(copy(db.schema))
      case 'me': return tail[0] === 'locale' ? setUiLocale(m, data) : ok({ ...copy(db.user), csrf: 'mock' })
      case 'languages': return ok({ main: LOCALE, list: [{ code: LOCALE, name: 'Русский' }] })
      case 'dashboard': return ok(dashboard())
      case 'pages': return pages(m, tail, query, data)
      case 'collections': return collections(m, tail, query, data)
      case 'notifications': return tail.length ? ok({ ok: true, unread: 0 }) : ok({ items: [], total: 0, unread: 0 })
      case 'seo-files': return seoFilesCall(m, data)
      case 'media': return ok([])
      case 'media-folders': return ok({ folders: [], total: 0, unfiled: 0, ratios: [] })
      case 'prefs': {
        const k = tail.join('/')
        if (m === 'GET') return ok({ value: k in db.prefs ? copy(db.prefs[k]) : null })
        if (m === 'PUT') { db.prefs[k] = copy(data.value); return ok({ value: db.prefs[k] }) }
        if (m === 'DELETE') { delete db.prefs[k]; return none() }
        return NOT_HERE()
      }
      default: return NOT_HERE()
    }
  }
}

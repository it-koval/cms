// The server of the mock mode (VITE_MOCK=1): `createMock()` answers { status, body } to { method, path, query, body } for the calls the screens make to /admin/api, from the data of
// fixtures.js. Everything written stays in memory, in the closure: a reload of the page starts again from the fixtures. Pure code, no DOM: install.js puts it behind `fetch`.
//
// What is answered: schema, me, languages, dashboard, collections (the rail), prefs, the bell, pages (list, draft, save, create, update, delete, publish, revert, unpublish, submit, return, tree)
// and records of collections (query, get, save, create, delete, translations, publish, revert, submit, return, order; the options matrix is one record 'main'), history, menus, settings texts, robots.txt / llms.txt (seo-files), Яндекс Вебмастер (yandex-webmaster), requests (with the delivery of each messenger) and the notification settings. Anything else is a 404 that says it is the demo.
import { LANGUAGES, makeDb } from './fixtures.js'

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
const RECORD_META = ['id', 'status', 'updatedAt', 'version', 'awaiting', 'returned', 'published', 'lastPublished', 'translations', 'pos']
const LOCALE = 'ru'

export function createMock(db = makeDb()) {
  const now = () => new Date().toISOString()
  const bump = (rec) => { rec.version = `v${++db.seq}`; rec.updatedAt = now() }
  const by = () => ({ by: db.user.name, at: now() })

  const codes = LANGUAGES.list.map((l) => l.code)
  const versions = (r) => ({ [LOCALE]: r, ...r.translations })
  const version = (r, locale) => locale === LOCALE ? r : r.translations?.[locale]
  const strip = (r) => Object.fromEntries(Object.entries(r).filter(([k]) => !['translations', 'published', 'lastPublished', 'pos'].includes(k)))
  const noTranslation = (locale) => fail(404, 'Перевода на этот язык нет', { code: 'no_translation', locale })
  const invalidLocale = () => fail(422, 'Выберите язык из списка')
  const localeOf = (query, body = {}) => query.locale ?? body.locale ?? LOCALE
  const validLocale = (locale) => typeof locale === 'string' && codes.includes(locale)
  const localeError = (query, body) => !validLocale(localeOf(query, body) || LOCALE) ? invalidLocale() : null
  const cfgOf = (key) => db.schema.collections.find((c) => c.key === key)
  const findPage = (id) => db.pages.find((p) => p.id === id)
  const dataKeys = (r) => Object.keys(r).filter((k) => !RECORD_META.includes(k))
  const pageDiff = (p) => p.published ? PAGE_KEYS.filter((k) => !eq(p[k], p.published[k])) : []
  const recDiff = (r) => r.published ? dataKeys(r).filter((k) => !eq(r[k], r.published[k])) : []
  const pageSummary = (p) => ({
    status: p.published ? 'published' : 'draft', changed: pageDiff(p).length > 0, changes: pageDiff(p).length, published: !!p.published, title: p.title, url: p.url,
    updatedAt: p.updatedAt, migrated: !!p.migrated, sectionsCount: (p.sections || []).length, awaiting: p.awaiting,
  })
  const recSummary = (cfg, r) => ({
    status: r.published ? 'published' : 'draft', changed: recDiff(r).length > 0, changes: recDiff(r).length, title: r[cfg.titleField] ?? '', updatedAt: r.updatedAt, awaiting: r.awaiting,
  })
  const summaries = (r, summarize) => Object.fromEntries(Object.entries(versions(r)).map(([loc, v]) => [loc, summarize(v)]))
  const flatPage = (p, locale = LOCALE) => {
    const t = version(p, locale)
    const source = t || p
    return { ...copy(strip(source)), id: p.id, parentId: p.parentId, template: p.template, locale, translated: !!t,
      ...pageSummary(source), ...(!t ? { status: 'missing', changed: false, changes: 0, published: false, version: null, updatedAt: null, awaiting: null, returned: null, migrated: false } : {}),
      locales: summaries(p, pageSummary) }
  }
  const snapshot = (p, locale = LOCALE) => {
    const t = version(p, locale)
    return t?.published ? { ...copy(strip(t.published)), id: p.id, locale, translated: true, status: 'published' } : null
  }
  const flatRecord = (cfg, r, locale = LOCALE) => {
    const t = version(r, locale)
    const source = t || r
    return { ...copy(strip(source)), id: r.id, locale, translated: !!t, ...recSummary(cfg, source),
      ...(!t ? { status: 'missing', changed: false, changes: 0, version: null, updatedAt: null, awaiting: null, returned: null } : {}),
      locales: summaries(r, (v) => recSummary(cfg, v)) }
  }
  const publishedRecord = (r, locale = LOCALE) => {
    const t = version(r, locale)
    return t?.published ? { ...copy(strip(t.published)), id: r.id, locale, translated: true, status: 'published' } : null
  }
  const inOrder = () => [...db.pages].sort((a, b) => (a.pos ?? 0) - (b.pos ?? 0))
  db.pages.forEach((p, i) => { if (p.pos === undefined) p.pos = i * 10 })
  const withKids = (id) => [id, ...db.pages.filter((p) => p.parentId === id).flatMap((p) => withKids(p.id))]

  /* History fixtures and writes use the same version identity as the real API. */
  let historySeq = 0
  const revisions = []
  const revisionSnapshots = new Map()
  const deletedObjects = new Map()
  const recordHistory = (kind, r, locale, action, key = '') => {
    const v = version(r, locale)
    if (!v) return
    const id = ++historySeq
    const at = now()
    revisions.unshift({ id, kind, resourceId: r.id, key, locale, action, userId: db.user.id, userName: db.user.name, via: 'admin', viaName: '', title: v.title || cfgOf(key)?.title || '', summary: [], baseline: false, createdAt: at, updatedAt: at })
    revisionSnapshots.set(id, copy(strip(v)))
  }
  const recordDeletion = (kind, r, key) => {
    recordHistory(kind, r, LOCALE, 'delete', key)
    deletedObjects.set(historySeq, { kind, key, record: copy(r) })
  }
  db.pages.forEach((p) => Object.keys(versions(p)).forEach((locale) => recordHistory('page', p, locale, 'create', p.template)))
  db.schema.collections.forEach((cfg) => db.collections[cfg.key].records.forEach((r) => Object.keys(versions(r)).forEach((locale) => recordHistory('item', r, locale, 'create', cfg.key))))
  recordHistory('page', db.pages[0], 'kk', 'edit', db.pages[0].template)
  const settingsState = (id, locale) => {
    const cfg = db.schema.settings.groups.find((g) => g.key === id)
    if (!cfg) return null
    return { id, title: cfg.title, ...Object.fromEntries(cfg.fields.map((f) => [f.k, f.tr ? db.settingsTexts[locale]?.[f.k] || '' : db.settings[f.k]])) }
  }
  const menuState = (id, locale) => {
    const area = db.schema.menuAreas.find((a) => a.key === id)
    return area ? { id, title: area.name, items: copy(db.menus[locale]?.[id] || []) } : null
  }
  const recordStateHistory = (kind, id, locale, action) => {
    const state = kind === 'settings' ? settingsState(id, locale) : menuState(id, locale)
    if (state) recordHistory(kind, locale === LOCALE ? state : { id, translations: { [locale]: state } }, locale, action, id)
  }
  Object.keys(db.settingsTexts).forEach((locale) => db.schema.settings.groups.forEach((g) => recordStateHistory('settings', g.key, locale, 'edit')))
  Object.keys(db.menus).forEach((locale) => db.schema.menuAreas.forEach((a) => recordStateHistory('menu', a.key, locale, 'edit')))

  function translation(method, r, locale, from, removeLocale, kind, cfg) {
    const target = method === 'DELETE' ? removeLocale : locale
    if (!validLocale(target)) return invalidLocale()
    if (method === 'DELETE') {
      if (!version(r, target)) return noTranslation(target)
      if (target === LOCALE || Object.keys(versions(r)).length < 2) return fail(409, 'Основной или последний перевод нельзя удалить')
      recordHistory(kind, r, target, 'delete_locale', cfg?.key || r.template)
      delete r.translations[target]
      return ok({ ok: true })
    }
    if (from !== undefined && from !== null && (!validLocale(from) || from === target)) return fail(422, 'Выберите другой язык для копирования')
    if (version(r, target)) return fail(409, 'Перевод уже существует', { code: 'translation_exists', locale: target })
    const source = from != null ? version(r, from) : r
    if (!source) return fail(422, 'В выбранном языке нет версии для копирования', { code: 'translation_source_missing' })
    let v
    if (kind === 'page') {
      const prefix = from && from !== LOCALE ? `/${from}` : ''
      const sourcePath = source.url === prefix ? '/' : source.url.slice(prefix.length) || '/'
      v = { ...copy(strip(source)), seo: { title: '', description: '' }, fields: {}, sections: [] }
      if (from != null) v = copy(strip(source))
      v.url = `/${target}${sourcePath === '/' ? '' : sourcePath}`
    } else {
      const fields = (cfg.tabs || []).flatMap((tab) => tab.fields || [])
      v = from != null ? Object.fromEntries(dataKeys(source).map((k) => [k, copy(source[k])])) : Object.fromEntries(fields.map((f) => [f.k, f.t === 'toggle' ? false : '']))
      if (cfg.view === 'matrix' && from == null) v = { cols: [], rows: [] }
    }
    v = { ...v, id: r.id, status: 'draft', updatedAt: now(), version: `v${++db.seq}`, awaiting: null, returned: null, published: null }
    r.translations ||= {}
    r.translations[target] = v
    recordHistory(kind, r, target, 'create', cfg?.key || r.template)
    return { status: 201, body: kind === 'page' ? flatPage(r, target) : flatRecord(cfg, r, target) }
  }

  /* ---------- pages ---------- */
  function pages(method, rest, query, body) {
    const [id, action, extra] = rest
    const locale = localeOf(query, body) || LOCALE
    if (!id) {
      if (method === 'GET') return ok(inOrder().filter((p) => (!query.template || p.template === query.template) && (query.parentId === undefined || (['null', 'root'].includes(query.parentId) ? p.parentId === null : !query.parentId || p.parentId === query.parentId))).map((p) => flatPage(p, locale)))
      if (method === 'POST') {
        const made = { id: `p${++db.seq}`, parentId: body.parentId ?? null, template: body.template || 'default', title: body.title || 'Без названия', url: body.url || '/', seo: { title: '', description: '' },
          fields: copy(body.fields) || {}, sections: copy(body.sections) || [], updatedAt: now(), version: `v${++db.seq}`, migrated: false, awaiting: null, returned: null, published: null, pos: db.pages.length * 10 + 5, translations: {} }
        db.pages.push(made)
        recordHistory('page', made, LOCALE, 'create', made.template)
        return { status: 201, body: flatPage(made) }
      }
      return NOT_HERE()
    }
    if (id === 'tree' && method === 'PUT') {
      const nodes = Array.isArray(body) ? body : body.nodes || []
      nodes.forEach((n) => {
        const p = findPage(n.id)
        if (!p) return
        p.parentId = n.parentId ?? null
        p.pos = Number(n.position) || 0
        Object.values(versions(p)).forEach(bump)
      })
      return ok({ ok: true, moved: nodes.length })
    }
    const p = findPage(id)
    if (!p) return NO_PAGE()
    if (action === 'translations' && ['POST', 'DELETE'].includes(method)) return translation(method, p, query.locale, body.from, extra, 'page')
    const t = version(p, locale)
    if (action === 'draft' && method === 'GET') return ok({ page: t ? flatPage(p, locale) : null, published: snapshot(p, locale), changes: [], locale, translated: !!t, locales: summaries(p, pageSummary), shell: { id: p.id, parentId: p.parentId, template: p.template } })
    if (!action && method === 'DELETE') {
      const gone = new Set(withKids(id))
      db.pages.filter((x) => gone.has(x.id)).forEach((x) => recordDeletion('page', x, x.template))
      db.pages = db.pages.filter((x) => !gone.has(x.id))
      return none()
    }
    if (!t) return noTranslation(locale)
    if (action === 'published' && method === 'GET') return ok(snapshot(p, locale))
    if (((action === 'draft' && method === 'PUT') || (action === 'publish' && method === 'POST')) && body.baseVersion && body.baseVersion !== t.version) return fail(409, 'Страницу изменили, пока она была открыта', { code: 'conflict', current: { version: t.version, ...by(), via: 'admin', viaName: '' } })
    if (!action && method === 'GET') return ok(flatPage(p, locale))
    if ((!action && method === 'PATCH') || (action === 'draft' && method === 'PUT')) {
      ;(action ? PAGE_KEYS : PAGE_EDIT).forEach((k) => { if (body[k] !== undefined) { t[k] = copy(body[k]); if (['parentId', 'template'].includes(k)) p[k] = copy(body[k]) } })
      bump(t)
      recordHistory('page', p, locale, 'edit', p.template)
      return ok(flatPage(p, locale))
    }
    if (method === 'POST' && extra === undefined) {
      if (action === 'publish') { t.published = copy(Object.fromEntries(PAGE_KEYS.map((k) => [k, t[k]]))); t.awaiting = null; t.returned = null; t.updatedAt = now() }
      else if (action === 'revert') { if (t.published) PAGE_KEYS.forEach((k) => { t[k] = copy(t.published[k]) }); bump(t) }
      else if (action === 'unpublish') {
        if (!t.published || (p.template === 'home' && locale === LOCALE)) return fail(409, 'Эту версию нельзя снять с публикации')
        t.lastPublished = copy(t.published); t.published = null; bump(t)
      }
      else if (action === 'republish' || action === 'undo-unpublish') {
        if (t.published || !t.lastPublished) return fail(409, 'Нет снятой с публикации версии')
        t.published = copy(t.lastPublished); delete t.lastPublished; bump(t)
      }
      else if (action === 'submit') { t.awaiting = by(); bump(t) }
      else if (action === 'return') { t.awaiting = null; bump(t) }
      else return NOT_HERE()
      recordHistory('page', p, locale, action, p.template)
      return ok(flatPage(p, locale))
    }
    return NOT_HERE()
  }

  /* ---------- collections ---------- */
  function compareBy(sort) {
    const keys = String(sort || '').split(',').filter(Boolean).map((k) => (k.startsWith('-') ? [k.slice(1), -1] : [k, 1]))
    keys.push(['id', 1])
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
  const truthy = (v) => [true, 1, '1', 'true', 'on', 'yes'].includes(v)

  function collections(method, rest, query, body) {
    const [key, id, action, extra] = rest
    const locale = localeOf(query, body) || LOCALE
    if (!key) return method === 'GET' ? ok(railList(query.locale || null)) : NOT_HERE()
    const cfg = cfgOf(key)
    const store = db.collections[key]
    if (!cfg || !store) return fail(404, 'Такой коллекции нет')
    const recs = store.records
    if (!id) {
      if (method === 'GET') {
        const status = query.status || ''
        if (!['', 'draft', 'published', 'missing'].includes(status)) return fail(422, 'Выберите статус из списка')
        const q = String(query.q || '').trim().toLowerCase()
        const filters = query.filter || {}
        const allowedFilters = ['status', ...(cfg.filters || []).map((f) => f.k)]
        const unknown = Object.keys(filters).find((k) => !allowedFilters.includes(k))
        if (unknown) return fail(422, 'Такой фильтр недоступен', { filter: unknown })
        const allRows = recs.map((r) => flatRecord(cfg, r, locale))
        let rows = allRows.filter((r) => {
          if (q && !(cfg.searchKeys || [cfg.titleField]).some((k) => String(r[k] ?? '').toLowerCase().includes(q))) return false
          if (truthy(query.onlyTranslated) && !r.translated) return false
          if (Object.entries(filters).some(([k, v]) => v !== '' && v != null && String(k === 'status' ? (r.translated ? r.status : '') : r[k] ?? '') !== String(v))) return false
          const draft = r.status === 'draft' || r.changed
          if (status === 'published') return r.translated && !draft
          if (status === 'draft') return r.translated && draft
          if (status === 'missing') return !r.translated
          return true
        })
        rows = rows.sort(compareBy(query.sort || cfg.defaultSort))
        const perPage = Math.max(1, Math.min(200, Math.trunc(Number(query.perPage)) || cfg.perPage || 25))
        const page = Math.max(1, Math.trunc(Number(query.page)) || 1)
        const facets = {}
        allRows.forEach((r) => {
          const status = !r.translated ? 'missing' : r.status === 'draft' || r.changed ? 'draft' : 'published'
          facets.status ||= {}; facets.status[status] = (facets.status[status] || 0) + 1
        })
        return ok({ items: rows.slice((page - 1) * perPage, page * perPage), total: rows.length, page, perPage, facets, metrics: [] })
      }
      if (method === 'POST') {
        if (cfg.singleton && recs.length) return fail(409, 'Единственная запись этой коллекции уже существует')
        const { locale: ignoredLocale, id: ignoredId, baseVersion, ...fields } = body
        const made = { ...copy(fields), id: `n${++db.seq}`, status: 'draft', updatedAt: now(), version: `v${++db.seq}`, awaiting: null, returned: null, published: null, translations: {} }
        recs.unshift(made)
        recordHistory('item', made, LOCALE, 'create', key)
        return { status: 201, body: flatRecord(cfg, made) }
      }
      return NOT_HERE()
    }
    if (id === 'order' && method === 'PUT') return ok({ ok: true })
    const r = recs.find((v) => v.id === id)
    if (!r) return NO_RECORD()
    if (action === 'translations' && ['POST', 'DELETE'].includes(method)) return translation(method, r, query.locale, body.from, extra, 'item', cfg)
    const t = version(r, locale)
    if (!action) {
      if (method === 'GET') return ok({ item: t ? flatRecord(cfg, r, locale) : null, published: publishedRecord(r, locale), changes: [], locale, translated: !!t, locales: summaries(r, (v) => recSummary(cfg, v)) })
      if (method === 'DELETE') { recordDeletion('item', r, key); store.records = recs.filter((x) => x.id !== id); return none() }
    }
    if (!t) return noTranslation(locale)
    if (((!action && ['PUT', 'PATCH'].includes(method)) || (action === 'publish' && method === 'POST')) && body.baseVersion && body.baseVersion !== t.version) return fail(409, 'Запись изменили, пока она была открыта', { code: 'conflict', current: { version: t.version, ...by(), via: 'admin', viaName: '' } })
    if (!action && ['PUT', 'PATCH'].includes(method)) {
      Object.entries(body).forEach(([k, v]) => { if (!['baseVersion', 'locale', ...RECORD_META].includes(k)) t[k] = copy(v) })
      bump(t)
      recordHistory('item', r, locale, 'edit', key)
      return ok(flatRecord(cfg, r, locale))
    }
    if (method === 'POST') {
      if (action === 'publish') { t.published = copy(Object.fromEntries(dataKeys(t).map((k) => [k, t[k]]))); t.awaiting = null; t.returned = null; t.updatedAt = now() }
      else if (action === 'revert') { if (t.published) dataKeys(t).forEach((k) => { t[k] = copy(t.published[k]) }); bump(t) }
      else if (action === 'submit') { t.awaiting = by(); bump(t) }
      else if (action === 'return') { t.awaiting = null; bump(t) }
      else return NOT_HERE()
      recordHistory('item', r, locale, action, key)
      return ok(flatRecord(cfg, r, locale))
    }
    return NOT_HERE()
  }

  const railList = (locale = null) => db.schema.collections.map((cfg) => {
    const rs = db.collections[cfg.key].records
    const locales = Object.fromEntries(codes.map((code) => {
      const vs = rs.map((r) => version(r, code)).filter(Boolean)
      return [code, { count: vs.length, unpublished: vs.filter((r) => !r.published || recDiff(r).length).length }]
    }))
    return { ...Object.fromEntries(['key', 'title', 'singular', 'newLabel', 'icon', 'view', 'description'].map((k) => [k, cfg[k]])), count: cfg.view === 'matrix' ? rs[0]?.cols?.length || 0 : rs.length,
      unpublished: locale ? locales[locale].unpublished : Object.values(locales).reduce((n, l) => n + l.unpublished, 0), locales }
  })

  /* ---------- «Сегодня» ---------- */
  function dashboard() {
    const unpublished = []
    const recent = []
    const locales = Object.fromEntries(codes.map((locale) => [locale, { pages: { count: 0, unpublished: 0 }, collections: { count: 0, unpublished: 0 } }]))
    for (const p of db.pages) for (const [locale, t] of Object.entries(versions(p))) {
      if (!codes.includes(locale)) continue
      ++locales[locale].pages.count
      if (!t.published || pageDiff(t).length) ++locales[locale].pages.unpublished
      const to = `/pages/${p.id}?lang=${locale}`
      if (!t.published || pageDiff(t).length) unpublished.push({ kind: 'page', id: p.id, locale, template: p.template, title: t.title, pageKind: 'Страница', to, changes: pageDiff(t).length, draft: !t.published, updatedAt: t.updatedAt, migrated: !!t.migrated, awaiting: t.awaiting })
      if (t.published) recent.push({ label: t.title, to, at: t.updatedAt, locale })
    }
    for (const cfg of db.schema.collections) for (const r of db.collections[cfg.key].records) for (const [locale, t] of Object.entries(versions(r))) {
      if (!codes.includes(locale)) continue
      ++locales[locale].collections.count
      if (!t.published || recDiff(t).length) ++locales[locale].collections.unpublished
      const to = `/collections/${cfg.key}${cfg.view === 'matrix' ? '' : `/${r.id}`}?lang=${locale}`
      if (!t.published || recDiff(t).length) unpublished.push({ kind: 'item', collection: cfg.key, id: r.id, locale, title: String(t[cfg.titleField] || cfg.title), pageKind: cfg.title, to, changes: recDiff(t).length, draft: !t.published, updatedAt: t.updatedAt, migrated: false, awaiting: t.awaiting })
      if (t.published) recent.push({ label: String(t[cfg.titleField] || cfg.title), to, at: t.updatedAt, locale })
    }
    const newest = (a, b) => String(b.updatedAt || b.at).localeCompare(String(a.updatedAt || a.at))
    return { newRequests: [], failedCount: 0, unpublished: unpublished.sort(newest), awaiting: unpublished.filter((r) => r.awaiting).map((r) => ({ ...r, by: r.awaiting.by, at: r.awaiting.at })), proposals: [], recent: recent.sort(newest).slice(0, 8), languages: copy(LANGUAGES), locales }
  }

  function history(method, rest, query) {
    const [kind, id, action] = rest
    const can = (r, right = 'view') => (db.user.perms[r.kind === 'page' ? 'pages' : r.kind === 'item' ? `col:${r.key}` : r.kind === 'menu' ? 'set:menus' : `set:${r.key}`] || []).includes(right) && (r.kind !== 'page' || !db.user.templates || db.user.templates.includes(r.key))
    const present = (r) => ({ ...copy(r), current: !['delete', 'delete_locale'].includes(r.action) && ![...deletedObjects.values()].some((v) => v.kind === r.kind && v.record.id === r.resourceId) && !revisions.some((other) => other.kind === r.kind && other.resourceId === r.resourceId && other.locale === r.locale && other.id > r.id) })
    if (kind === 'trash' && method === 'GET') {
      if (query.kind && !['page', 'item'].includes(query.kind)) return fail(422, 'Выберите страницы или записи')
      const items = revisions.filter((r) => deletedObjects.has(r.id) && can(r) && (!query.kind || r.kind === query.kind) && (!query.key || r.key === query.key)).map((r) => {
        const gone = deletedObjects.get(r.id).record
        return { id: r.id, kind: r.kind, resourceId: r.resourceId, key: r.key, keyTitle: r.kind === 'page' ? db.schema.templates.find((t) => t.key === r.key)?.name || r.key : cfgOf(r.key)?.title || r.key,
          title: r.title, userId: r.userId, userName: r.userName, deletedAt: r.createdAt, locales: Object.keys(versions(gone)), wasLive: Object.values(versions(gone)).some((v) => !!v.published), canRestore: can(r, 'edit'), restorable: true }
      })
      return ok({ items, total: items.length })
    }
    if (kind === 'revision') {
      const r = revisions.find((v) => v.id === Number(id))
      if (!r) return fail(404, 'Такой версии нет')
      if (!can(r, action === 'restore' ? 'edit' : 'view')) return fail(403, 'Нет доступа')
      if (method === 'GET') {
        const object = r.kind === 'page' ? findPage(r.resourceId) : r.kind === 'item' ? db.collections[r.key]?.records.find((v) => v.id === r.resourceId) : r.kind === 'settings' ? settingsState(r.resourceId, r.locale) : menuState(r.resourceId, r.locale)
        const exists = object && (!['page', 'item'].includes(r.kind) || version(object, r.locale))
        return ok({ ...present(r), against: [], inTrash: deletedObjects.has(r.id), canRestore: r.action !== 'delete_locale' && can(r, 'edit') && (r.action === 'delete' ? deletedObjects.has(r.id) : !!exists && validLocale(r.locale)) })
      }
      if (method === 'POST' && action === 'restore') {
        if (r.action === 'delete_locale') return fail(409, 'Удалённый перевод нельзя восстановить этой операцией')
        if (r.action === 'delete') {
          const gone = deletedObjects.get(r.id)
          if (!gone) return fail(409, 'Этот объект уже восстановлен')
          const object = copy(gone.record)
          if (r.kind === 'page') {
            if (findPage(object.id)) return fail(409, 'Страница уже существует')
            if (object.parentId && !findPage(object.parentId)) object.parentId = null
            db.pages.push(object)
          } else {
            const store = db.collections[r.key]
            if (!store || store.records.some((v) => v.id === object.id)) return fail(409, 'Запись уже существует')
            store.records.push(object)
          }
          deletedObjects.delete(r.id)
          Object.entries(versions(object)).forEach(([locale, t]) => {
            t.published = null; delete t.lastPublished; t.awaiting = null; t.returned = null; bump(t)
            recordHistory(r.kind, object, locale, 'restore', r.key)
          })
          return ok({ ok: true, kind: r.kind, id: r.resourceId, key: r.key, locale: r.locale, restored: 'trash' })
        }
        if (r.kind === 'settings' || r.kind === 'menu') {
          const snapshot = revisionSnapshots.get(r.id)
          if (r.kind === 'menu') { db.menus[r.locale] ||= {}; db.menus[r.locale][r.resourceId] = copy(snapshot.items) }
          else db.schema.settings.groups.find((g) => g.key === r.resourceId).fields.forEach((f) => {
            if (f.tr) { db.settingsTexts[r.locale] ||= {}; db.settingsTexts[r.locale][f.k] = copy(snapshot[f.k]) }
            else db.settings[f.k] = copy(snapshot[f.k])
          })
          recordStateHistory(r.kind, r.resourceId, r.locale, 'restore')
          return ok({ ok: true, kind: r.kind, id: r.resourceId, key: r.key, locale: r.locale, restored: 'version' })
        }
        const object = r.kind === 'page' ? findPage(r.resourceId) : db.collections[r.key]?.records.find((v) => v.id === r.resourceId)
        if (!object || !version(object, r.locale)) return fail(422, 'Перевода на этот язык нет', { code: 'no_translation', locale: r.locale })
        const t = version(object, r.locale)
        Object.entries(revisionSnapshots.get(r.id)).forEach(([k, v]) => { if (![...RECORD_META, 'parentId', 'template'].includes(k)) t[k] = copy(v) })
        bump(t)
        recordHistory(r.kind, object, r.locale, 'restore', r.key)
        return ok({ ok: true, kind: r.kind, id: r.resourceId, key: r.key, locale: r.locale, restored: 'version' })
      }
      return NOT_HERE()
    }
    if (!['page', 'item', 'settings', 'menu'].includes(kind) || method !== 'GET') return NOT_HERE()
    const object = kind === 'page' ? findPage(id) : kind === 'settings' ? settingsState(id, query.locale || LOCALE) : kind === 'menu' ? menuState(id, query.locale || LOCALE) : db.schema.collections.flatMap((c) => db.collections[c.key].records).find((r) => r.id === id)
    const all = revisions.filter((r) => r.kind === kind && r.resourceId === id)
    if (!object && !all.length) return fail(404, 'Такого объекта нет')
    if (!can(all[0])) return fail(403, 'Нет доступа')
    const rows = all.filter((r) => query.scope === 'all' || r.locale === (query.locale || LOCALE))
    const limit = Math.max(1, Math.min(200, Math.trunc(Number(query.limit ?? 50)) || 1))
    const offset = Math.max(0, Math.trunc(Number(query.offset)) || 0)
    return ok({ items: rows.slice(offset, offset + limit).map(present), total: rows.length, limit, offset, keep: 100, days: 365 })
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

  /* ---------- Яндекс Вебмастер: GET/DELETE yandex-webmaster, POST …/connect, …/refresh. Every state can be reached by the token pasted into the form:
     `demo:<state>` (not_connected, no_address, no_http_client, token_invalid, verifying, verification_failed, ready) with modifiers `+viewer` (no right to manage), `+expiring`, `+error`, `+empty` (Yandex has no data yet).
     `bad` is refused by Yandex (422), `down` is a 502; any other token connects and gives `verifying`, a refresh makes it `ready`. `?ym=ready+expiring` in the address starts the page in a state. ---------- */
  const YM_HOST = { id: 'https:demo.example.test:443', url: 'https://demo.example.test' }
  const YM_QUERIES = [['веб-студия томск', 120, 14], ['сайт под ключ', 96, 9], ['разработка сайтов цена', 74, 6], ['itk cms', 40, 12], ['заказать лендинг', 33, null], ['сделать сайт для клиники', 21, 2], ['сайт визитка цена', 18, 1], ['seo продвижение томск', null, null]]
  let ym = { state: 'not_connected', canManage: true, expiring: false, error: null, empty: false, at: null }
  const ymBody = () => {
    const connected = ['token_invalid', 'verifying', 'verification_failed', 'ready'].includes(ym.state)
    const day = (n) => new Date(Date.now() + n * 864e5).toISOString()
    const ready = ym.state === 'ready'
    return {
      state: ym.state, canManage: ym.canManage, tokenLink: 'https://oauth.yandex.ru/authorize?response_type=token&client_id=demo',
      connectedAt: connected ? day(-170) : null, tokenExpiresAt: connected ? day(ym.expiring ? 6 : 170) : null, tokenExpiresSoon: connected && ym.expiring,
      host: connected ? YM_HOST : null, webmasterUrl: connected ? 'https://webmaster.yandex.ru/site/https:demo.example.test:443/dashboard/' : null,
      verification: connected && ym.state !== 'token_invalid' ? { state: ready ? 'VERIFIED' : ym.state === 'verifying' ? 'IN_PROGRESS' : 'VERIFICATION_FAILED', failReason: ym.state === 'verification_failed' ? 'META_TAG_NOT_FOUND' : null } : null,
      summary: ready && !ym.empty ? { sqi: 1240, searchable: 120, excluded: 7, problems: { FATAL: 0, CRITICAL: 1, POSSIBLE_PROBLEM: 3, RECOMMENDATION: 5 } } : null,
      queries: ready && !ym.empty ? { from: '2026-10-30', to: '2026-11-05', rows: YM_QUERIES.map(([text, shows, clicks]) => ({ text, shows, clicks })) } : null,
      updatedAt: connected ? ym.at || now() : null, error: connected && ym.error ? 'Яндекс сейчас не отвечает, показаны данные последнего обновления' : null,
    }
  }
  function ymConnect(token) {
    const spec = String(token || '').trim()
    if (!spec) return fail(422, 'Вставьте токен')
    if (spec === 'bad') return fail(422, 'Яндекс не принял этот токен. Проверьте, что вы скопировали его целиком')
    if (spec === 'down') return fail(502, 'Яндекс не отвечает, попробуйте через минуту')
    const [state, ...mods] = spec.startsWith('demo:') ? spec.slice(5).split('+') : ['verifying']
    ym = { state: state || 'verifying', canManage: !mods.includes('viewer'), expiring: mods.includes('expiring'), error: mods.includes('error') ? true : null, empty: mods.includes('empty'), at: now() }
    return ok(ymBody())
  }
  function yandexWebmaster(method, tail, data) {
    if (tail[0] === 'connect' && method === 'POST') return ymConnect(data.token)
    if (tail[0] === 'refresh' && method === 'POST') {
      if (ym.state === 'verifying') ym = { ...ym, state: 'ready' }
      ym.at = now()
      return ok(ymBody())
    }
    if (tail.length) return NOT_HERE()
    if (method === 'GET') return ok(ymBody())
    if (method === 'DELETE') {
      ym = { state: 'not_connected', canManage: true, expiring: false, error: null, empty: false, at: null }
      return ok(ymBody())
    }
    return NOT_HERE()
  }

  /* ---------- requests: the delivery state of every messenger (`channels`) and the aggregate the server derives from it ---------- */
  const CHANNEL_TITLES = { telegram: 'Telegram', max: 'MAX' }
  const present = (r) => {
    const failedOnes = r.channels.filter((c) => c.state === 'failed')
    const delivery = failedOnes.length ? 'failed' : r.channels.some((c) => c.state === 'sent') ? 'sent' : 'pending'
    return { ...copy(r), delivery, deliveryError: failedOnes.map((c) => `${CHANNEL_TITLES[c.channel]}: ${c.error}`).join('\n') || null, st: delivery === 'failed' ? 'failed' : 'delivered' }
  }
  const requestCounts = () => ({ total: db.requests.length, new: db.requests.filter((r) => r.work === 'new').length, failed: db.requests.filter((r) => present(r).st === 'failed').length })
  function requestsCall(method, tail, query, data) {
    if (!tail.length) {
      if (method !== 'GET') return NOT_HERE()
      const q = String(query.q ?? '').trim().toLowerCase()
      const digits = q.replace(/\D/g, '')
      const matches = (r) => {
        if (!q) return true
        if (digits) return String(r.phone ?? '').replace(/\D/g, '').includes(digits)
        return [r.name, r.city, r.form].some((value) => String(value ?? '').toLowerCase().includes(q))
      }
      const rows = db.requests.map(present).filter((r) => (!query.status || (query.status === 'failed' ? r.st === 'failed' : r.work === query.status))
        && (!query.form || r.form === query.form) && matches(r))
      return ok(rows)
    }
    if (tail[0] === 'counts') return ok(requestCounts())
    const r = db.requests.find((x) => String(x.id) === tail[0])
    if (!r) return fail(404, 'Такой заявки нет')
    if (!tail[1] && method === 'GET') return ok(present(r))
    if (!tail[1] && method === 'PATCH') {
      if (data.work) r.work = data.work
      if (typeof data.comment === 'string') r.comment = data.comment
      return ok(present(r))
    }
    if (tail[1] === 'resend' && method === 'POST') {
      const wanted = data.channel ? [data.channel] : r.channels.filter((c) => c.state === 'failed').map((c) => c.channel)
      r.channels.filter((c) => wanted.includes(c.channel)).forEach((c) => {
        c.state = 'sent'
        c.error = null
        r.log.push(['ok', `${CHANNEL_TITLES[c.channel]}: sent again`, now()])
      })
      const p = present(r)
      return ok({ ok: true, delivery: p.delivery, error: p.deliveryError, ...p })
    }
    return NOT_HERE()
  }

  /* ---------- shared settings and per-language texts; readiness answers are never taken from a PATCH ---------- */
  const SETTINGS_READ_ONLY = ['tgFallback', 'tgReady', 'maxFallback', 'mailReady', 'maxReady']
  function botsMetadata() {
    return Object.fromEntries(['telegram', 'max'].map((key) => {
      const bot = db.bots[key]
      const source = bot.token ? 'admin' : (bot.serverToken ? 'server' : null)
      return [key, { tokenSet: !!source, source, ready: !!source }]
    }))
  }
  function botsCall(method, data) {
    if (method === 'GET') return ok(botsMetadata())
    if (method !== 'PUT') return NOT_HERE()
    const errors = {}
    for (const key of ['telegram', 'max']) {
      if (!(key in data)) continue
      const value = data[key]
      if (!value || typeof value !== 'object' || Array.isArray(value)
        || ('token' in value && typeof value.token !== 'string')
        || ('clear' in value && typeof value.clear !== 'boolean')) {
        errors[key] = 'Invalid token settings'
        continue
      }
      const token = value.token ?? ''
      const valid = key === 'telegram'
        ? /^\d{5,20}:[A-Za-z0-9_-]{30,}$/.exec(token)?.[0] === token
        : [...token].length <= 512 && !/\s/u.test(token) && ![...token].some((char) => char.codePointAt(0) < 32 || char.codePointAt(0) === 127)
      if (token !== '' && !valid) errors[key] = 'Invalid token settings'
    }
    if (Object.keys(errors).length) return fail(422, 'Check the bot token settings', errors)
    for (const key of ['telegram', 'max']) {
      const value = data[key]
      if (!value) continue
      if (value.clear) db.bots[key].token = ''
      else if (value.token) db.bots[key].token = value.token
    }
    const metadata = botsMetadata()
    db.settings.tgReady = metadata.telegram.ready
    db.settings.maxReady = metadata.max.ready
    return ok(metadata)
  }
  function settingsCall(method, tail, data, locale = LOCALE) {
    if (tail.length === 1 && tail[0] === 'bots') return botsCall(method, data)
    if (!tail.length) {
      if (method === 'GET') return ok({ ...copy(db.settings), siteTitle: db.settingsTexts[locale]?.siteTitle || '' })
      if (method !== 'PATCH') return NOT_HERE()
      Object.entries(data).filter(([k]) => !SETTINGS_READ_ONLY.includes(k)).forEach(([k, v]) => {
        if (k === 'siteTitle') { db.settingsTexts[locale] ||= {}; db.settingsTexts[locale][k] = copy(v) }
        else db.settings[k] = copy(v)
      })
      db.schema.settings.groups.forEach((g) => recordStateHistory('settings', g.key, locale, 'edit'))
      return ok({ ...copy(db.settings), siteTitle: db.settingsTexts[locale]?.siteTitle || '' })
    }
    const [what] = tail
    if (method !== 'POST' || !['telegram-test', 'max-test'].includes(what)) return NOT_HERE()
    const chat = String(data.chatId || '').trim()
    const ids = chat.split(',').map((x) => x.trim()).filter(Boolean)
    if (!ids.length) return fail(422, 'No chat to send to')
    if (what === 'telegram-test' && db.settings.tgReady === false) return fail(502, 'Telegram is not connected')
    if (what === 'max-test' && !db.settings.maxReady) return fail(502, 'MAX is not connected')
    if (ids.includes('0')) return fail(502, 'The messenger refused the message: chat not found')
    return ok({ ok: true, chat: String(data.chat ?? data.form ?? 'other'), sent: ids.length })
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
      case 'languages': return ok(copy(LANGUAGES))
      case 'dashboard': return ok(dashboard())
      case 'pages': return localeError(query, data) || pages(m, tail, query, data)
      case 'collections': return localeError(query, data) || collections(m, tail, query, data)
      case 'requests': return requestsCall(m, tail, query, data)
      case 'settings': return localeError(query, data) || settingsCall(m, tail, data, localeOf(query, data) || LOCALE)
      case 'menu-areas': return m === 'GET' ? ok(db.schema.menuAreas.map((area) => ({
        ...copy(area), snippet: `{{ itk_menu('${area.key}') }}`, usedIn: [], usedInAll: false,
        counts: { ru: 0, ...Object.fromEntries(Object.entries(db.menus).map(([locale, menus]) => [locale, (menus[area.key] || []).length])) },
      }))) : NOT_HERE()
      case 'menus': {
        const error = localeError(query, data)
        if (error) return error
        const locale = localeOf(query, data) || LOCALE
        if (m === 'PUT') {
          db.menus[locale] = copy(data)
          db.schema.menuAreas.forEach((a) => recordStateHistory('menu', a.key, locale, 'edit'))
          return ok(copy(db.menus[locale]))
        }
        return m === 'GET' ? ok(copy(db.menus[locale] || Object.fromEntries(db.schema.menuAreas.map((a) => [a.key, []])))) : NOT_HERE()
      }
      case 'redirects': return ok([])
      case 'history': return localeError(query) || history(m, tail, query)
      case 'notifications': return tail.length ? ok({ ok: true, unread: 0 }) : ok({ items: [], total: 0, unread: 0 })
      case 'seo-files': return seoFilesCall(m, data)
      case 'yandex-webmaster': return yandexWebmaster(m, tail, data)
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

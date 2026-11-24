import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import vm from 'node:vm'

const asset = new URL('../../public/analytics.js', import.meta.url)

function browser(attributes = {}, options = {}) {
  const created = []
  const inserted = []
  const events = []
  const windowEvents = []
  const errors = []
  const documentListeners = []
  const windowListeners = []
  const script = {
    getAttribute(name) {
      return ({ 'data-start': 'manual', ...attributes })[name] ?? null
    },
  }
  const window = {
    addEventListener(name, listener) {
      if (name === 'itk-analytics:started') windowListeners.push(listener)
    },
    ...options.globals,
  }
  const document = {
    currentScript: options.fallback ? null : script,
    scripts: [script, ...(options.scripts || [])],
    querySelector(selector) {
      assert.equal(selector, 'script[data-itk-analytics]')
      return options.noScript ? null : script
    },
    createElement(tagName) {
      const node = { tagName }
      created.push(node)
      return node
    },
    head: {
      appendChild(node) {
        assert.equal(node.tagName, 'script')
        inserted.push(node)
        document.scripts.push(node)
        options.onInsert?.(window, node)
      },
    },
    addEventListener(name, listener) {
      if (name === 'itk-analytics:started') documentListeners.push(listener)
    },
    dispatchEvent(event) {
      events.push(event)
      for (const listener of documentListeners) listener(event)
      if (event.bubbles) {
        windowEvents.push(event)
        for (const listener of windowListeners) listener(event)
      }
      return true
    },
  }
  class CustomEvent {
    constructor(type, options) {
      this.type = type
      Object.assign(this, options)
    }
  }
  const context = vm.createContext({
    window, document, CustomEvent,
    console: { error(...args) { errors.push(args) } },
  })
  function evaluate() {
    vm.runInContext(readFileSync(asset, 'utf8'), context, { filename: 'analytics.js' })
  }
  evaluate()
  return { window, document, created, inserted, events, windowEvents, errors, evaluate }
}

function values(value) {
  return JSON.parse(JSON.stringify(value))
}

function counters(events) {
  return events.map(event => values(event.detail.counters))
}

const configured = { 'data-metrika': '12345678', 'data-webvisor': '1', 'data-ga': 'G-ABC123XYZ9' }

test('manual creates the API synchronously without external elements, globals or events', () => {
  const page = browser(configured)
  assert.equal(typeof page.window.itkAnalytics.start, 'function')
  assert.deepEqual(values(page.window.itkAnalytics.started), [])
  assert.deepEqual(page.created, [])
  assert.deepEqual(page.events, [])
  assert.equal(page.window.ym, undefined)
  assert.equal(page.window.gtag, undefined)
  assert.equal(page.window.dataLayer, undefined)
})

test('partial consent starts only GA, then start() starts only the remaining Metrika', () => {
  const page = browser(configured)
  page.window.itkAnalytics.start(['ga'])
  assert.deepEqual(page.inserted.map(node => node.src), ['https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ9'])
  assert.equal(page.inserted[0].async, true)
  assert.equal(page.window.ym, undefined)
  assert.deepEqual(values(page.window.itkAnalytics.started), ['ga'])
  assert.deepEqual(Array.from(page.window.dataLayer, args => Array.from(args).slice(0, 1)), [['js'], ['config']])
  assert.equal(Object.prototype.toString.call(page.window.dataLayer[0][1]), '[object Date]')
  assert.equal(page.window.dataLayer[1][1], 'G-ABC123XYZ9')

  page.window.itkAnalytics.start()
  assert.deepEqual(page.inserted.map(node => node.src), [
    'https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ9',
    'https://mc.yandex.ru/metrika/tag.js?id=12345678',
  ])
  assert.equal(page.inserted[1].async, true)
  assert.deepEqual(values(page.window.itkAnalytics.started), ['ga', 'metrika'])
  assert.deepEqual(counters(page.events), [['ga'], ['metrika']])
  assert.deepEqual(values(Array.from(page.window.ym.a[0])), [
    '12345678', 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: true },
  ])
  assert.equal(typeof page.window.ym.l, 'number')
})

test('start() starts all configured counters in order and dispatches one bubbling event', () => {
  const page = browser(configured)
  const observed = []
  page.document.addEventListener('itk-analytics:started', event => {
    observed.push(['document', values(event.detail.counters), page.inserted.length, values(page.window.itkAnalytics.started)])
  })
  page.window.addEventListener('itk-analytics:started', event => observed.push(['window', values(event.detail.counters)]))
  page.window.itkAnalytics.start()
  assert.deepEqual(counters(page.events), [['metrika', 'ga']])
  assert.equal(page.events[0].type, 'itk-analytics:started')
  assert.equal(page.events[0].bubbles, true)
  assert.equal(page.windowEvents[0], page.events[0])
  assert.deepEqual(observed, [
    ['document', ['metrika', 'ga'], 2, ['metrika', 'ga']],
    ['window', ['metrika', 'ga']],
  ])
})

test('existing site ym, gtag and dataLayer are preserved and used', () => {
  const metrikaCalls = []
  const gaCalls = []
  const ym = (...args) => metrikaCalls.push(args)
  ym.l = 123
  ym.a = [['queued-site-goal']]
  const gtag = (...args) => gaCalls.push(args)
  const dataLayer = [['queued-site-event']]
  const page = browser(configured, { globals: { ym, gtag, dataLayer } })

  page.window.itkAnalytics.start()

  assert.equal(page.window.ym, ym)
  assert.equal(ym.l, 123)
  assert.deepEqual(ym.a, [['queued-site-goal']])
  assert.deepEqual(values(metrikaCalls), [
    ['12345678', 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: true }],
  ])
  assert.equal(page.window.gtag, gtag)
  assert.deepEqual(gaCalls.map(args => args[0]), ['js', 'config'])
  assert.equal(gaCalls[1][1], 'G-ABC123XYZ9')
  assert.equal(page.window.dataLayer, dataLayer)
  assert.deepEqual(dataLayer, [['queued-site-event']])
  assert.deepEqual(values(page.window.itkAnalytics.started), ['metrika', 'ga'])
  assert.deepEqual(counters(page.events), [['metrika', 'ga']])
})

test('the GA queue stub appends to an existing site dataLayer', () => {
  const dataLayer = [['queued-site-event']]
  const page = browser(configured, { globals: { dataLayer } })

  page.window.itkAnalytics.start(['ga'])

  assert.equal(page.window.dataLayer, dataLayer)
  assert.deepEqual(dataLayer[0], ['queued-site-event'])
  assert.deepEqual(Array.from(dataLayer.slice(1), args => args[0]), ['js', 'config'])
  assert.equal(dataLayer[2][1], 'G-ABC123XYZ9')
})

test('provider failures are isolated and only failed counters can be retried', () => {
  for (const failed of ['metrika', 'ga']) {
    const failure = new Error(failed + ' initialization failed')
    const calls = []
    const global = failed === 'metrika' ? 'ym' : 'gtag'
    const page = browser(configured, { globals: { [global]() { throw failure } } })

    assert.doesNotThrow(() => page.window.itkAnalytics.start())

    const successful = failed === 'metrika' ? 'ga' : 'metrika'
    assert.equal(page.inserted.length, 1)
    assert.deepEqual(values(page.window.itkAnalytics.started), [successful])
    assert.deepEqual(counters(page.events), [[successful]])
    assert.equal(page.errors.length, 1)
    assert.match(page.errors[0][0], new RegExp(failed))
    assert.equal(page.errors[0][1], failure)

    page.window[global] = (...args) => calls.push(args)
    page.window.itkAnalytics.start()
    page.window.itkAnalytics.start()

    assert.equal(page.inserted.length, 2)
    assert.equal(calls.length, failed === 'metrika' ? 1 : 2)
    assert.deepEqual(values(page.window.itkAnalytics.started), [successful, failed])
    assert.deepEqual(counters(page.events), [[successful], [failed]])
    assert.equal(page.errors.length, 1)
  }
})

test('incompatible existing globals stay intact and failed selections emit no started event', () => {
  const ym = {}
  const gtag = {}
  const page = browser(configured, { globals: { ym, gtag } })

  assert.doesNotThrow(() => page.window.itkAnalytics.start())

  assert.equal(page.window.ym, ym)
  assert.equal(page.window.gtag, gtag)
  assert.deepEqual(page.created, [])
  assert.deepEqual(values(page.window.itkAnalytics.started), [])
  assert.deepEqual(page.events, [])
  assert.equal(page.errors.length, 2)
  assert.match(page.errors[0][0], /metrika/)
  assert.match(page.errors[1][0], /ga/)

  delete page.window.ym
  delete page.window.gtag
  page.window.itkAnalytics.start()

  assert.equal(page.inserted.length, 2)
  assert.deepEqual(values(page.window.itkAnalytics.started), ['metrika', 'ga'])
  assert.deepEqual(counters(page.events), [['metrika', 'ga']])
})

test('script insertion failures release reservations while reentrant starts stay idempotent', () => {
  const failure = new Error('script insertion failed')
  let fail = true
  const page = browser(configured, {
    onInsert(window, node) {
      window.itkAnalytics.start()
      if (fail && node.src.includes('googletagmanager.com')) {
        fail = false
        throw failure
      }
    },
  })

  page.window.itkAnalytics.start()

  assert.deepEqual(values(page.window.itkAnalytics.started), ['metrika'])
  assert.deepEqual(counters(page.events), [['metrika']])
  assert.equal(page.errors[0][1], failure)
  page.window.itkAnalytics.start()
  page.window.itkAnalytics.start()

  assert.equal(page.inserted.length, 3)
  assert.equal(page.window.ym.a.length, 1)
  assert.deepEqual(values(page.window.itkAnalytics.started), ['metrika', 'ga'])
  assert.deepEqual(counters(page.events), [['metrika'], ['ga']])
})

test('Webvisor is omitted unless its attribute is exactly 1', () => {
  for (const flag of [undefined, '', '0', 'true']) {
    const page = browser({ 'data-metrika': '12345678', 'data-webvisor': flag })
    page.window.itkAnalytics.start()
    assert.deepEqual(values(page.window.ym.a[0][2]), { clickmap: true, trackLinks: true, accurateTrackBounce: true })
  }
})

test('Metrika initializes but reuses an existing vendor script only for its exact URL', () => {
  for (const existing of ['https://mc.yandex.ru/metrika/tag.js?id=12345678', 'https://mc.yandex.ru/metrika/tag.js?id=87654321']) {
    const page = browser(configured, { scripts: [{ src: existing }] })
    assert.deepEqual(page.created, [])
    page.window.itkAnalytics.start(['metrika'])
    assert.equal(page.inserted.length, existing.endsWith('12345678') ? 0 : 1)
    assert.deepEqual(values(Array.from(page.window.ym.a[0])), [
      '12345678', 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: true },
    ])
    assert.deepEqual(values(page.window.itkAnalytics.started), ['metrika'])
    assert.deepEqual(counters(page.events), [['metrika']])
    page.window.itkAnalytics.start(['metrika'])
    assert.equal(page.window.ym.a.length, 1)
    assert.equal(page.events.length, 1)
  }
})

test('unknown, inherited object names, unconfigured and duplicate keys are ignored', () => {
  const page = browser({ 'data-ga': 'G-ABC123XYZ9' })
  page.window.itkAnalytics.start(['unknown', '__proto__', 'toString', 'metrika'])
  assert.deepEqual(page.created, [])
  assert.deepEqual(page.events, [])
  page.window.itkAnalytics.start(['unknown', 'ga', 'ga', 'metrika'])
  assert.equal(page.inserted.length, 1)
  assert.deepEqual(values(page.window.itkAnalytics.started), ['ga'])
  assert.deepEqual(counters(page.events), [['ga']])
})

test('empty and invalid lists do not grant consent', () => {
  const page = browser(configured)
  for (const list of [[], null, 'ga', {}, 1, false]) page.window.itkAnalytics.start(list)
  assert.deepEqual(page.created, [])
  assert.deepEqual(page.events, [])
})

test('repeated and overlapping calls while provider scripts are pending stay idempotent', async () => {
  const page = browser(configured)
  await Promise.all([
    Promise.resolve().then(() => page.window.itkAnalytics.start(['ga'])),
    Promise.resolve().then(() => page.window.itkAnalytics.start(['ga', 'metrika'])),
    Promise.resolve().then(() => page.window.itkAnalytics.start()),
  ])
  page.window.itkAnalytics.start(['metrika', 'ga', 'ga'])
  assert.equal(page.inserted.length, 2)
  assert.equal(page.window.ym.a.length, 1)
  assert.equal(page.window.dataLayer.length, 2)
  assert.deepEqual(counters(page.events), [['ga'], ['metrika']])
})

test('reentrant start calls during script insertion cannot duplicate selected counters', () => {
  const page = browser(configured, {
    onInsert(window) { window.itkAnalytics.start() },
  })
  page.window.itkAnalytics.start()
  assert.equal(page.inserted.length, 2)
  assert.deepEqual(counters(page.events), [['metrika', 'ga']])
})

test('event listeners can start remaining counters and cannot mutate the internal started state', () => {
  const page = browser(configured)
  page.document.addEventListener('itk-analytics:started', event => {
    event.detail.counters.push('edited')
    page.window.itkAnalytics.start()
  })
  page.window.itkAnalytics.start(['ga'])
  assert.equal(page.inserted.length, 2)
  assert.deepEqual(values(page.window.itkAnalytics.started), ['ga', 'metrika'])
  page.window.itkAnalytics.started.length = 0
  page.window.itkAnalytics.start()
  assert.equal(page.inserted.length, 2)
  assert.equal(page.events.length, 2)
})

test('auto starts through the same API during evaluation and is immediately idempotent', () => {
  const page = browser({ ...configured, 'data-start': 'auto' })
  assert.deepEqual(values(page.window.itkAnalytics.started), ['metrika', 'ga'])
  assert.equal(page.inserted.length, 2)
  assert.deepEqual(counters(page.events), [['metrika', 'ga']])
  page.window.itkAnalytics.start()
  assert.equal(page.inserted.length, 2)
  assert.equal(page.events.length, 1)
})

test('fallback script selector works and currentScript takes precedence', () => {
  const fallback = browser({ ...configured, 'data-start': 'auto' }, { fallback: true })
  assert.equal(fallback.inserted.length, 2)
  const current = browser(configured, { noScript: true })
  assert.equal(typeof current.window.itkAnalytics.start, 'function')
  assert.equal(current.inserted.length, 0)
})

test('empty configuration and a missing loader element remain inert', () => {
  for (const options of [{}, { fallback: true, noScript: true }]) {
    const page = browser({ 'data-start': 'auto', 'data-metrika': '', 'data-ga': '' }, options)
    page.window.itkAnalytics.start()
    assert.deepEqual(page.created, [])
    assert.deepEqual(page.events, [])
  }
})

test('reevaluating a loader retains the existing API and never starts a counter twice', () => {
  const page = browser({ ...configured, 'data-start': 'auto' })
  const api = page.window.itkAnalytics
  page.evaluate()
  assert.equal(page.window.itkAnalytics, api)
  assert.equal(page.inserted.length, 2)
  assert.equal(page.events.length, 1)
})

// Puts the mock server (handler.js) behind `fetch`: requests to the API of the admin are answered in the browser, everything else goes where it went. Called from main.js before
// the app starts, only when the page was built with VITE_MOCK=1 (`npm run dev:mock`); a production build does not contain this folder.
import { createMock, parseQuery } from './handler.js'
import { locale, locales, remembered } from '@/i18n/index.js'

const BASE = '/admin'
const LATENCY = 40 // ms: a screen that waits for the server shows its loading state for a moment, as it does with the real one

export function installMock() {
  const mock = createMock()
  mock({ method: 'PUT', path: 'me/locale', query: {}, body: { locale: remembered() } }) // what the person chose stays in this browser, see i18n/index.js
  const me = mock({ method: 'GET', path: 'me', query: {} }).body
  window.ITK_ADMIN = {
    adminBase: BASE, apiBase: `${BASE}/api`, csrf: me.csrf, user: me, site: { name: 'Демо-сайт', domain: 'demo.example.test' }, mainLocale: 'ru', locale, locales: locales(), previewBase: `${BASE}/preview`,
    loginUrl: `${BASE}/login`, logoutUrl: `${BASE}/logout`, assetsBase: '/bundles/itkcms',
  }
  const real = window.fetch.bind(window)
  window.fetch = async (input, init = {}) => {
    const url = new URL(typeof input === 'string' ? input : input.url, window.location.href)
    const prefix = `${BASE}/api/`
    if (!url.pathname.startsWith(prefix)) return real(input, init)
    let body
    if (typeof init.body === 'string' && init.body) {
      try { body = JSON.parse(init.body) } catch (e) { body = undefined }
    }
    const answer = mock({ method: init.method || 'GET', path: url.pathname.slice(prefix.length), query: parseQuery(url.searchParams), body })
    await new Promise((resolve) => setTimeout(resolve, LATENCY))
    return new Response(answer.status === 204 ? null : JSON.stringify(answer.body), { status: answer.status, headers: { 'Content-Type': 'application/json' } })
  }
  console.info('[ITK] Режим VITE_MOCK: данные выдуманные и живут в памяти страницы, сервер не нужен.')
}

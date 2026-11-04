// Pages of the current site as <select> options for link fields: [{ value:'/about-us', label:'О компании' }].
// Language: only the pages that HAVE a version in the current content language are offered, with the address of that version ('/en/about-us'):
// a link in English content must lead to an English page (an untranslated page is not on the site in that language).
// The list is re-read when the site / language changes and when it is older than a few seconds (a page may have been translated, published or removed meanwhile).
import { ref, watch } from 'vue'
import { api } from '@/api/index.js'
import { app } from '@/stores/app.js'

const options = ref([])
let loadedFor = ''
let loadedAt = 0
let inflight = null
const FRESH_MS = 4000

async function load() {
  const key = app.locale
  if (loadedFor === key && Date.now() - loadedAt < FRESH_MS) return
  if (inflight && loadedFor === key) return inflight
  loadedFor = key
  const loc = app.locale
  inflight = api.pages.list({ locale: loc }).then((pages) => {
    if (loc !== app.locale) return // the language changed while loading: the newer load wins
    options.value = pages.filter((p) => p.translated).map((p) => ({ value: p.url, label: p.title }))
    loadedAt = Date.now()
  }).catch(() => { loadedFor = '' }).finally(() => { inflight = null })
  return inflight
}
watch(() => [app.locale], () => { loadedFor = ''; loadedAt = 0; load() })

export function usePageOptions() {
  load()
  return options
}

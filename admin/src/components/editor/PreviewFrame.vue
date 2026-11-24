<script setup>
// The page as visitors see it: an iframe of /admin/preview/{pageId}?locale=ru. The SERVER renders the DRAFT with the site's own templates, marks every editable
// text / photo (data-f, data-sec) and every section (data-sec + data-type), and adds the small script preview.js (packages/cms/public/preview.js), which draws the
// hover outline with the section toolbar.
//
// postMessage protocol (same origin, only messages from this frame are accepted):
//   page -> admin   itk:focus   { sec, path, rows? }   a text / photo / section was clicked; rows holds stable repeater identities ('' path = the section; sec '@page' = template fields)
//   page -> admin   itk:action  { sec, act }    a toolbar button: act = edit | mv-up | mv-down | dup | toggle-hide | del (short names like up, hide are understood, too)
//   admin -> page   itk:select  { sec, path? }  the section whose form is open (sec '' = none): the page outlines it, scrolls to it when it is off screen and flashes field `path`
//   admin -> page   itk:reload                  reload the page keeping the scroll position (sent after the draft was saved: `reloadKey` changes)
// The frame never talks to anything else: a missing preview.js only means that the page cannot be clicked, the reload then falls back to location.reload().
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { adminBase, boot } from '@/api/http.js'
import { t } from '@/i18n/index.js'

const props = defineProps({
  pageId: { type: String, required: true },
  locale: { type: String, default: '' },
  reloadKey: { type: Number, default: 0 },
  selected: { type: String, default: null }, // section id, '@page' or null
  clean: { type: Boolean, default: false }, // the full-screen preview: the editing overlay of preview.js is hidden
  phone: { type: Boolean, default: false },
})
const emit = defineEmits(['focus', 'action', 'ready'])

const frame = ref(null)
const busy = ref(true)
const src = computed(() => {
  const base = String(boot().previewBase || adminBase() + '/preview').replace(/\/+$/, '')
  return `${base}/${encodeURIComponent(props.pageId)}${props.locale ? '?locale=' + encodeURIComponent(props.locale) : ''}`
})

const ACT = {
  edit: 'edit', open: 'edit', form: 'edit',
  up: 'up', 'move-up': 'up', 'mv-up': 'up', moveup: 'up',
  down: 'down', 'move-down': 'down', 'mv-down': 'down', movedown: 'down',
  dup: 'dup', duplicate: 'dup', copy: 'dup',
  hide: 'hide', show: 'hide', 'toggle-hide': 'hide', toggle: 'hide',
  del: 'del', delete: 'del', remove: 'del',
}

const win = () => (frame.value ? frame.value.contentWindow : null)
const doc = () => { try { return win() ? win().document : null } catch (e) { return null } }
const post = (msg) => { try { if (win()) win().postMessage(msg, window.location.origin) } catch (e) { /* the frame is gone */ } }

function onMessage(e) {
  if (e.source !== win() || e.origin !== window.location.origin) return
  const d = e.data
  if (!d || typeof d !== 'object' || typeof d.type !== 'string') return
  if (d.type === 'itk:focus') emit('focus', { sec: d.sec || '', path: d.path || '', ...(Array.isArray(d.rows) ? { rows: d.rows } : {}) })
  else if (d.type === 'itk:action') {
    const act = ACT[String(d.act || '').toLowerCase()]
    if (act) emit('action', { sec: d.sec || '', act })
  }
}

/* ---------- reloading without losing the place ---------- */
let reloading = false
let pending = false
let timer = null
let fallback = null

function reload() {
  if (reloading) { pending = true; return }
  if (!win()) return
  reloading = true
  busy.value = true
  post({ type: 'itk:reload' }) // preview.js remembers the scroll position and reloads the page
  clearTimeout(fallback)
  fallback = setTimeout(() => { // no preview.js answered: reload by hand
    if (!reloading) return
    try { win().location.reload() } catch (e) { frame.value.src = src.value }
  }, 1500)
}
watch(() => props.reloadKey, () => {
  clearTimeout(timer)
  timer = setTimeout(reload, 120)
})
watch(src, () => { reloading = false; busy.value = true }) // another page / language: a fresh load from the top

// the full-screen preview shows the page as visitors get it: the overlay and the edit outlines of preview.js are switched off
const CLEAN_CSS = '.itk-pv{display:none!important}[data-f],[data-f]:hover,[data-f].chg,[data-f][data-chg]{outline:none!important;cursor:auto!important}'

function onLoad() {
  const d = doc()
  if (props.clean && d && !d.getElementById('itk-admin-clean')) {
    const st = d.createElement('style')
    st.id = 'itk-admin-clean'
    st.textContent = CLEAN_CSS
    d.head.appendChild(st)
  }
  clearTimeout(fallback)
  const again = pending
  reloading = false
  pending = false
  busy.value = false
  select(props.selected, false)
  emit('ready')
  if (again) reload()
}

/* ---------- the selected section ---------- */
const esc = (s) => (window.CSS && CSS.escape ? CSS.escape(s) : String(s).replace(/"/g, '\\"'))
/** The root element of a section in the frame (or the first element of the template's own fields). */
function sectionEl(id) {
  const d = doc()
  if (!d || !id) return null
  return d.querySelector(`[data-sec="${esc(id)}"][data-type]`) || d.querySelector(`[data-sec="${esc(id)}"]`)
}
/** Bring a section into view: block 'nearest' leaves a visible one alone, 'start' / 'center' place it. */
function reveal(id, block = 'nearest') {
  const el = sectionEl(id)
  const w = win()
  if (!el || !w) return
  const r = el.getBoundingClientRect()
  const h = w.innerHeight
  let d = 0
  if (block === 'start') d = r.top - 12
  else if (block === 'center') d = r.top - (h - Math.min(r.height, h)) / 2
  else if (r.top < 0 || r.top > h - 40) d = r.top - 12
  else if (r.bottom > h) d = Math.min(r.top - 12, r.bottom - h + 12)
  if (d) w.scrollTo({ top: w.scrollY + d, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
}
function select(id, scroll = true) {
  post({ type: 'itk:select', sec: id || '' })
  if (id && scroll) reveal(id)
}
watch(() => props.selected, (id) => select(id))

/** Wash a field (or the section) magenta for a moment: preview.js flashes the field and scrolls to it. */
function flash(id, key) {
  post({ type: 'itk:select', sec: id || '', path: key || undefined })
}
/** The list a template fills by itself sits below the sections: bring the end of the page into view. */
function showEnd() {
  const w = win()
  const d = doc()
  if (!w || !d) return
  const el = d.querySelector('[data-listing], .itk-listing')
  if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' })
  else w.scrollTo({ top: d.documentElement.scrollHeight, behavior: 'smooth' })
}

onMounted(() => window.addEventListener('message', onMessage))
onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage)
  clearTimeout(timer)
  clearTimeout(fallback)
})
defineExpose({ reveal, flash, showEnd, reload })
</script>

<template>
  <div :class="['pv-wrap', { busy }]">
    <iframe ref="frame" class="pv-iframe" :class="{ phone }" :src="src" :title="t('editor.frame_title')" @load="onLoad" />
  </div>
</template>

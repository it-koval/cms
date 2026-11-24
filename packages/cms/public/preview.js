/*
 * ITK CMS: preview script. Loaded into the page that the admin shows in its editor iframe (/admin/preview/{id}).
 * The server marks the page: a section root has data-sec, data-type, data-label (and data-chg when it differs from the published one);
 * a field has data-f="path" data-sec="<section id>|@page" (and the class `chg` / attribute data-chg when it differs).
 *
 * Messages (window.postMessage, same origin, event.data = { type, ...payload }):
 *   page -> admin   itk:ready  { sections: [{ sec, type, label }] }          the page is drawn (sent on every load)
 *                   itk:focus  { sec, path, rows? }                          a field ([data-f]) or the section was clicked; rows = stable repeater ids in path order; path '' = the section itself, sec '@page' = a field of the page
 *                   itk:action { sec, act }                                  act: 'edit' | 'mv-up' | 'mv-down' | 'dup' | 'toggle-hide' | 'del'
 *   admin -> page   itk:select { sec, path? }                                outline the section the open form belongs to (sec '' clears); path scrolls to / flashes that field
 *                   itk:reload                                               reload keeping the scroll position (a reload from outside keeps it as well)
 * window.ITK_PREVIEW = { readonly: true } (set by the server for a person who may only look) hides the actions: only «Смотреть» stays.
 * window.ITK_PREVIEW.texts holds the words of this script in the language of the person, the page around them stays in its own: the script has no catalogue, see PreviewController::texts().
 */
(function () {
  'use strict'
  if (window.__itkPreview || !document.documentElement) return
  window.__itkPreview = true

  var cfg = window.ITK_PREVIEW || {}
  var readonly = !!cfg.readonly || /[?&]readonly=1(&|$)/.test(location.search)
  var parentWin = window.parent && window.parent !== window ? window.parent : null
  var ORIGIN = location.origin
  var SEC = '[data-sec][data-type]'
  var KEY = 'itk-preview-scroll'
  var TEXTS = cfg.texts || {}

  function post(type, payload) {
    if (!parentWin) return
    var m = { type: type }
    for (var k in payload) m[k] = payload[k]
    parentWin.postMessage(m, ORIGIN)
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] }) }
  /* a word of the overlay, ready for HTML; {label} is the name of a section */
  function say(name, label) { return esc(String(TEXTS[name] || name).replace('{label}', function () { return label })) }
  function q(sel, root) { try { return (root || document).querySelector(sel) } catch (e) { return null } }
  function byId(id) { return id ? q('[data-sec="' + String(id).replace(/["\\]/g, '\\$&') + '"][data-type]') : null }
  function sections() { return Array.prototype.slice.call(document.querySelectorAll(SEC)) }

  /* ---------- scroll survives a reload ---------- */
  try { history.scrollRestoration = 'manual' } catch (e) { /* old browser */ }
  function rememberScroll() {
    try { sessionStorage.setItem(KEY, JSON.stringify({ href: location.pathname + location.search, y: window.pageYOffset || 0, sel: selected, t: Date.now() })) } catch (e) { /* storage blocked */ }
  }
  var restored = null
  try {
    var saved = JSON.parse(sessionStorage.getItem(KEY) || 'null')
    if (saved && saved.href === location.pathname + location.search && Date.now() - saved.t < 15000) restored = saved
    sessionStorage.removeItem(KEY)
  } catch (e) { /* storage blocked */ }
  window.addEventListener('pagehide', rememberScroll)

  /* ---------- overlay (it lives in <html>, positioned in document coordinates, never inside the site's layout) ---------- */
  var ICONS = {
    edit: '<path d="m3 13 .6-2.8 6.9-6.9a1.4 1.4 0 0 1 2 2L5.6 12.2 3 13Z"/>',
    view: '<path d="M2 8s2.3-4 6-4 6 4 6 4-2.3 4-6 4-6-4-6-4Z"/><circle cx="8" cy="8" r="1.8"/>',
    'mv-up': '<path d="M8 13V3M3.5 7.5 8 3l4.5 4.5"/>',
    'mv-down': '<path d="M8 3v10M3.5 8.5 8 13l4.5-4.5"/>',
    dup: '<rect x="5.5" y="5.5" width="7.5" height="7.5" rx="1.5"/><path d="M3 10.5V4.5a1.5 1.5 0 0 1 1.5-1.5h6"/>',
    'toggle-hide': '<path d="M2 8s2.3-4 6-4 6 4 6 4-2.3 4-6 4-6-4-6-4Z"/><circle cx="8" cy="8" r="1.8"/><path d="M3 13 13 3"/>',
    del: '<path d="M3 4.5h10M6.5 4.5V3h3v1.5M4.5 4.5l.6 8h5.8l.6-8"/>',
  }
  function icon(name) { return '<svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[name] + '</svg>' }
  var TOOLS = [['mv-up', 'move_up'], ['mv-down', 'move_down'], ['dup', 'duplicate'], ['toggle-hide', 'hide'], ['del', 'delete']]

  var ui = document.createElement('div')
  ui.className = 'itk-pv'
  ui.setAttribute('data-itk-pv', '')
  ui.innerHTML = '<div class="itk-pv-sel" hidden></div><div class="itk-pv-hover" hidden></div><div class="itk-pv-marks"></div>'
  var selBox = ui.children[0]
  var hoverBox = ui.children[1]
  var marksBox = ui.children[2]
  document.documentElement.appendChild(ui)

  var current = null // hovered section element
  var selected = restored && restored.sel ? restored.sel : '' // section id of the open form
  var raf = 0

  function place(box, el) {
    var r = el.getBoundingClientRect()
    if (r.width < 1 || r.height < 1) { box.hidden = true; return false }
    box.hidden = false
    box.style.top = r.top + (window.pageYOffset || 0) + 'px'
    box.style.left = r.left + (window.pageXOffset || 0) + 'px'
    box.style.width = r.width + 'px'
    box.style.height = r.height + 'px'
    if (box === hoverBox) box.classList.toggle('itk-pv-edge', r.top < 32) // the toolbar of a finger straddles the top edge of the section: near the top of the window there is no room above it, so it moves inside
    return true
  }

  function fillHover(el) {
    var list = sections()
    var i = list.indexOf(el)
    var label = el.getAttribute('data-label') || el.getAttribute('data-type') || ''
    var html = '<div class="itk-pv-tag"><span class="itk-pv-name">' + esc(label) + '</span>' +
      '<button type="button" class="itk-pv-btn itk-pv-txt" data-act="edit" aria-label="' + say(readonly ? 'view_section' : 'edit_section', label) + '">' +
      icon(readonly ? 'view' : 'edit') + '<span>' + say(readonly ? 'view' : 'edit') + '</span></button></div>'
    if (!readonly) {
      html += '<div class="itk-pv-tools" role="toolbar" aria-label="' + say('section_actions', label) + '">'
      TOOLS.forEach(function (t) {
        var off = (t[0] === 'mv-up' && i === 0) || (t[0] === 'mv-down' && i === list.length - 1)
        html += '<button type="button" class="itk-pv-btn' + (t[0] === 'del' ? ' itk-pv-danger' : '') + '" data-act="' + t[0] + '" title="' + say(t[1]) + '" aria-label="' + say(t[1]) + '"' + (off ? ' disabled' : '') + '>' + icon(t[0]) + '</button>'
      })
      html += '</div>'
    }
    hoverBox.innerHTML = html
  }

  function setCurrent(el) {
    if (el === current) return
    current = el
    if (!el) { hoverBox.hidden = true; return }
    fillHover(el)
    place(hoverBox, el)
  }

  var coarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches)
  function layout() {
    raf = 0
    // a finger has no hover: the section whose form is open carries the toolbar
    if (coarse && !current && selected && byId(selected)) setCurrent(byId(selected))
    if (current && !place(hoverBox, current)) current = null
    var s = byId(selected)
    if (s) place(selBox, s); else selBox.hidden = true
    drawMarks()
  }
  function schedule() { if (!raf) raf = requestAnimationFrame(layout) }

  /* a small magenta mark on every section that has unpublished changes */
  function drawMarks() {
    var html = ''
    sections().forEach(function (el) {
      if (!el.hasAttribute('data-chg')) return
      var r = el.getBoundingClientRect()
      if (r.width < 1 || r.height < 1) return
      html += '<span class="itk-pv-mark" style="top:' + (r.top + (window.pageYOffset || 0) + 8) + 'px;left:' + (r.left + (window.pageXOffset || 0) + 8) + 'px" title="' + say('unpublished') + '"></span>'
    })
    if (marksBox.getAttribute('data-h') !== html) { marksBox.innerHTML = html; marksBox.setAttribute('data-h', html) }
  }

  /* ---------- hover ---------- */
  document.addEventListener('mousemove', function (e) {
    var t = e.target
    if (!(t instanceof Element) || t.closest('.itk-pv-tag, .itk-pv-tools')) return
    if (downType && downType !== 'mouse' && Date.now() - downAt < 1500) return // the mouse events a browser sends after a tap are not a hover
    setCurrent(t.closest(SEC))
  }, { passive: true })
  /* A finger: the toolbar is drawn when a tap is over (in the click handler below), never under a finger that is already down.
     A button of the toolbar works for a finger only if the toolbar was on the screen when the finger came down; a tap that merely
     lands where a button will appear shows the toolbar and does nothing else. */
  var downType = ''
  var downAt = 0
  var downOnTool = false
  document.addEventListener('pointerdown', function (e) {
    downType = e.pointerType || ''
    downAt = Date.now()
    var t = e.target
    downOnTool = t instanceof Element && !!t.closest('.itk-pv-btn')
  }, { passive: true, capture: true })
  function touched(e) {
    var type = e.pointerType || (Date.now() - downAt < 1500 ? downType : '')
    return !!type && type !== 'mouse'
  }
  document.documentElement.addEventListener('mouseleave', function () { setCurrent(null) })
  window.addEventListener('scroll', schedule, { passive: true })
  window.addEventListener('resize', schedule)
  window.addEventListener('load', function () { schedule(); announce() })
  if (typeof ResizeObserver === 'function') new ResizeObserver(schedule).observe(document.body || document.documentElement)

  /* ---------- clicks: the preview is not a live site ---------- */
  document.addEventListener('click', function (e) {
    var t = e.target instanceof Element ? e.target : e.target && e.target.parentElement
    if (!(t instanceof Element)) return
    var btn = t.closest('.itk-pv-btn')
    if (btn) {
      e.preventDefault()
      e.stopPropagation()
      if (btn.disabled || !current) return
      if (touched(e) && !downOnTool) return // the toolbar appeared under the finger: this tap was meant for the page
      post('itk:action', { sec: current.getAttribute('data-sec'), act: btn.getAttribute('data-act') })
      return
    }
    if (t.closest('.itk-pv')) return
    if (touched(e)) setCurrent(t.closest(SEC)) // a tap shows the toolbar of that section (no hover comes before it)
    var f = t.closest('[data-f]')
    var disclosure = f && t.closest('summary') && !t.closest('a[href], button, [type=submit]')
    if (!disclosure && t.closest('a[href], button, [type=submit], label')) e.preventDefault()
    if (f) {
      // The same click opens the field and lets the browser expand a native FAQ item.
      if (!disclosure) {
        e.preventDefault()
        e.stopPropagation()
      }
      var focus = { sec: f.getAttribute('data-sec') || '', path: f.getAttribute('data-f') || '' }
      if (f.hasAttribute('data-row-ids')) {
        try { focus.rows = JSON.parse(f.getAttribute('data-row-ids')) } catch (err) { focus.rows = [] }
      }
      post('itk:focus', focus)
      return
    }
    var s = t.closest(SEC)
    if (s) post('itk:focus', { sec: s.getAttribute('data-sec'), path: '' })
  }, true)
  document.addEventListener('submit', function (e) { e.preventDefault() }, true)

  /* ---------- messages from the admin ---------- */
  function flash(el) {
    el.classList.add('itk-pv-flash')
    setTimeout(function () { el.classList.remove('itk-pv-flash') }, 1400)
  }
  function onSelect(m) {
    selected = m.sec ? String(m.sec) : ''
    var el = byId(selected)
    var target = null
    if (m.path) {
      var sel = '[data-f="' + String(m.path).replace(/["\\]/g, '\\$&') + '"]'
      target = (el && q(sel, el)) || q(sel + '[data-sec="' + String(selected).replace(/["\\]/g, '\\$&') + '"]')
    }
    var focusEl = target || el
    if (focusEl) {
      var r = focusEl.getBoundingClientRect()
      if (r.bottom < 0 || r.top > window.innerHeight) focusEl.scrollIntoView({ block: 'center', behavior: 'smooth' })
      if (target) flash(target)
    }
    schedule()
  }
  window.addEventListener('message', function (e) {
    if (e.origin !== ORIGIN || (parentWin && e.source !== parentWin)) return
    var m = e.data
    if (!m || typeof m !== 'object') return
    if (m.type === 'itk:select') onSelect(m)
    else if (m.type === 'itk:reload') { rememberScroll(); location.reload() }
  })

  function announce() {
    post('itk:ready', {
      sections: sections().map(function (el) { return { sec: el.getAttribute('data-sec'), type: el.getAttribute('data-type'), label: el.getAttribute('data-label') || '' } }),
    })
  }

  function start() {
    if (restored) window.scrollTo(0, restored.y)
    layout()
    announce()
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start()
  if (restored) window.addEventListener('load', function () { window.scrollTo(0, restored.y) })
})()

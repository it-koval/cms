// Allowed-HTML whitelist. Rich-text values are stored as HTML strings; this is the ONLY place that decides what HTML may
// be stored or rendered. Used by RichText (on output), and by the field forms (on input).
//
// modes:
//   'headline' — inline only: <br> and <span class="grad"> (the gradient only when the site design has it, see setHeadlineMarks). A block (<p>/<div>) boundary becomes <br>.
//   'rich'     — the DESCRIPTION mode, for all prose of the site: <p> <br> <strong> <em> <a href> <ul> <ol> <li>. No gradient, no headings.
//   'article'  — long texts (blog): rich, plus <h2> <h3> <blockquote> <hr> <img src alt [width] [height] [data-align]> and a simple table:
//                <table> <thead> <tbody> <tr> <th> <td> without attributes, except a numeric colspan / rowspan (2 and more) on <th> and <td>.
//                A table is kept only at the top level of the text; a cell holds inline text only (<strong> <em> <a> <br>), blocks inside a cell become line breaks.
//                The leading rows made of <th> cells only go to <thead>, the rest to <tbody> (the editor has no <thead>, so this keeps the round trip stable).
export const GRAD_CLASS = 'grad'

// Design capability of the CURRENT site: may a headline carry gradient words? The config is SITE_DESIGN in schema/sections.js; stores/app.js
// calls setHeadlineMarks when a site is chosen. Read by the sanitiser (a site without gradient text drops the spans) and by the headline editor.
let headlineGradient = true
export const setHeadlineMarks = (marks) => { headlineGradient = (marks || []).includes('gradient') }
export const headlineMarks = () => (headlineGradient ? ['gradient'] : [])

export const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]))

const DROP = new Set(['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'head', 'title'])
const BLOCK = new Set(['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'section', 'article'])
// tags that start a new line inside a table cell (a cell holds inline text only)
const CELL_BLOCK = new Set(['ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption'])
const lineBlock = (tag, mode) => BLOCK.has(tag) || (mode === 'cell' && CELL_BLOCK.has(tag))
const SAFE_HREF = /^(https?:\/\/|mailto:|tel:|\/|#)/i
// photos of an article: the media library (relative path, data URL of an uploaded photo) or an absolute address
const SAFE_SRC = /^(assets\/[^"'<>\s]+|\/[^"'<>\s]+|https?:\/\/[^"'<>\s]+|data:image\/(png|jpe?g|webp|gif|avif);base64,[A-Za-z0-9+/=]+)$/i

function parse(html) {
  return new DOMParser().parseFromString(`<body>${html ?? ''}</body>`, 'text/html').body
}

function inline(node, mode) {
  let out = ''
  node.childNodes.forEach((n) => { out += walk(n, mode) })
  return out
}

function walk(node, mode) {
  if (node.nodeType === 3) return escapeHtml(node.nodeValue)
  if (node.nodeType !== 1) return ''
  const tag = node.tagName.toLowerCase()
  if (DROP.has(tag)) return ''
  if (tag === 'br') return '<br>'
  if (mode === 'article' && tag === 'table') return table(node)
  const kids = inline(node, mode)
  if (tag === 'span') {
    if (mode !== 'headline' || !headlineGradient || !node.classList.contains(GRAD_CLASS)) return kids
    // a line break stays OUTSIDE the gradient (as on the live site): every line gets its own span, so recolouring one line never touches another
    return kids.split('<br>').map((part) => (part.trim() === '' ? part : `<span class="${GRAD_CLASS}">${part}</span>`)).join('<br>')
  }
  if (mode === 'headline' || mode === 'cell') {
    if (lineBlock(tag, mode)) {
      // a block that follows text on the same line starts a new line (<span>a<div>b</div></span> is two lines, as in the browser)
      const p = node.previousSibling
      const after = p && ((p.nodeType === 3 && p.nodeValue.trim() !== '') || (p.nodeType === 1 && p.tagName.toLowerCase() !== 'br' && !lineBlock(p.tagName.toLowerCase(), mode))) ? '<br>' : ''
      return after + kids + (node.nextSibling ? '<br>' : '')
    }
    if (mode === 'headline') return kids
  }
  if (mode === 'article') {
    switch (tag) {
      case 'h1': case 'h2': return kids.trim() ? `<h2>${kids}</h2>` : ''
      case 'h3': case 'h4': case 'h5': case 'h6': return kids.trim() ? `<h3>${kids}</h3>` : ''
      case 'blockquote': return kids.trim() ? `<blockquote>${/^<p>/.test(kids) ? kids : `<p>${kids}</p>`}</blockquote>` : ''
      case 'hr': return '<hr>'
      case 'img': {
        const src = (node.getAttribute('src') || '').trim()
        if (!SAFE_SRC.test(src)) return ''
        const alt = (node.getAttribute('alt') || '').trim()
        // width and height: positive whole numbers only (the display size the author set; the site uses it for sizes/srcset)
        const size = ['width', 'height'].map((d) => { const v = (node.getAttribute(d) || '').trim(); return /^\d{1,5}$/.test(v) && Number(v) > 0 ? ` ${d}="${Number(v)}"` : '' }).join('')
        const align = (node.getAttribute('data-align') || '').trim().toLowerCase()
        return `<img src="${escapeHtml(src)}" alt="${escapeHtml(alt)}"${size}${['left', 'center', 'right'].includes(align) ? ` data-align="${align}"` : ''}>`
      }
      default: break
    }
  }
  switch (tag) {
    case 'strong': case 'b': return kids ? `<strong>${kids}</strong>` : ''
    case 'em': case 'i': return kids ? `<em>${kids}</em>` : ''
    case 'a': {
      const href = (node.getAttribute('href') || '').trim()
      if (!SAFE_HREF.test(href)) return kids
      const ext = /^https?:/i.test(href)
      return `<a href="${escapeHtml(href)}"${ext ? ' target="_blank" rel="noopener noreferrer"' : ''}>${kids}</a>`
    }
    case 'ul': case 'ol': return kids ? `<${tag}>${kids}</${tag}>` : ''
    case 'li': return `<li>${kids}</li>`
    case 'p': case 'div': case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6': case 'blockquote': case 'section': case 'article':
      return `<p>${kids}</p>`
    case 'pre': return `<p>${kids}</p>`
    default: return kids
  }
}

/** The inline HTML of a table cell: <strong> <em> <a> <br> and text; every block inside becomes a line break; nothing else survives. */
const cell = (node) => inline(node, 'cell').replace(/^(?:\s|<br>)+|(?:\s|<br>)+$/g, '').replace(/(?:<br>\s*){2,}/g, '<br>') // dropped images and rules must not leave empty lines

// only a numeric colspan / rowspan of 2 and more survives (the attribute part of the opening tag, without «>»)
const cellAttrs = (node) => ['colspan', 'rowspan'].map((n) => { const v = (node.getAttribute(n) || '').trim(); return /^[1-9]\d{0,2}$/.test(v) && Number(v) > 1 ? ` ${n}="${Number(v)}"` : '' }).join('')

/** A table only at the top level of the text; anywhere else (in a paragraph, a quote, a list, another cell) its text is flattened into lines. */
function table(node) {
  if (!node.parentNode || node.parentNode.nodeName.toLowerCase() !== 'body') return cell(node)
  const rows = []
  const collect = (holder, top) => {
    for (const c of holder.children) {
      const tag = c.tagName.toLowerCase()
      if (tag === 'tr') {
        const cells = []
        for (const td of c.children) {
          const name = td.tagName.toLowerCase()
          if (name === 'td' || name === 'th') cells.push([name, `${cellAttrs(td)}>${cell(td)}`])
        }
        if (cells.length) rows.push(cells)
      } else if (top && ['thead', 'tbody', 'tfoot'].includes(tag)) collect(c, false)
    }
  }
  collect(node, true)
  if (!rows.length) return ''
  const render = (set) => set.map((cells) => `<tr>${cells.map(([name, inner]) => `<${name}${inner}</${name}>`).join('')}</tr>`).join('')
  const head = []
  while (rows.length && rows[0].every((c) => c[0] === 'th')) head.push(rows.shift())
  return `<table>${head.length ? `<thead>${render(head)}</thead>` : ''}${rows.length ? `<tbody>${render(rows)}</tbody>` : ''}</table>`
}

/** Returns a safe HTML string. Rich mode guarantees block structure: loose inline runs are wrapped in <p>. */
export function sanitizeHtml(html, mode = 'rich') {
  if (mode !== 'headline' && mode !== 'article') mode = 'rich'
  if (html == null || html === '') return ''
  const body = parse(String(html))
  if (mode === 'headline') {
    let out = inline(body, 'headline')
    out = out.replace(/(<br>)+$/g, (m) => (m.length > 4 ? '<br>' : m)) // keep at most one trailing break (the editor shows the empty line)
    return out
  }
  let out = ''
  let run = ''
  const flush = () => { if (run.trim() !== '') out += `<p>${run}</p>`; run = '' }
  body.childNodes.forEach((n) => {
    const tag = n.nodeType === 1 ? n.tagName.toLowerCase() : ''
    const asBlock = BLOCK.has(tag) || tag === 'ul' || tag === 'ol' || (mode === 'article' && (tag === 'hr' || tag === 'img' || tag === 'table'))
    if (n.nodeType === 1 && asBlock) { flush(); out += walk(n, mode) } else run += walk(n, mode)
  })
  flush()
  return mode === 'article' ? out.replace(/(<p><\/p>)+$/, '') : out
}

/** Plain text of an HTML value: <br> and block ends become "\n". */
export function htmlToText(html) {
  if (html == null || html === '') return ''
  const body = parse(String(html))
  let out = ''
  const go = (n) => {
    if (n.nodeType === 3) { out += n.nodeValue; return }
    if (n.nodeType !== 1) return
    const tag = n.tagName.toLowerCase()
    if (DROP.has(tag)) return
    if (tag === 'br') { out += '\n'; return }
    n.childNodes.forEach(go)
    if (tag === 'p' || tag === 'li' || tag === 'div' || tag === 'h2' || tag === 'h3' || tag === 'blockquote' || tag === 'tr') out += '\n'
    else if (tag === 'td' || tag === 'th') out += ' '
  }
  body.childNodes.forEach(go)
  return out.replace(/\n+$/g, '')
}

/** The first non-empty line of a headline as plain text (<br> separates the lines): the page name a multi-line heading stands for. */
export function firstLineText(html) {
  for (const line of htmlToText(html).split('\n')) { const t = line.replace(/\s+/g, ' ').trim(); if (t) return t }
  return ''
}

/** Length for the «n / max» budget: characters without line breaks. */
export const textLength = (html) => [...htmlToText(html).replace(/\n/g, '')].length
export const isHtmlEmpty = (html) => htmlToText(html).trim() === ''

/** Plain text -> HTML for the given mode. Headline: "\n" -> <br>. Rich: a blank line starts a paragraph, a single "\n" is a <br>. Everything is escaped. */
export function textToHtml(text, mode = 'rich') {
  const t = String(text ?? '').replace(/\r/g, '')
  if (t === '') return ''
  if (mode === 'headline') return escapeHtml(t).replace(/\n/g, '<br>')
  return t.split(/\n{2,}/).map((c) => c.replace(/^\n+|\n+$/g, '')).filter((c) => c !== '').map((c) => `<p>${escapeHtml(c).replace(/\n/g, '<br>')}</p>`).join('')
}

/** Two HTML values are the same text after sanitising (so re-normalising is never a «change»). */
export const sameHtml = (a, b, mode = 'rich') => sanitizeHtml(a, mode) === sanitizeHtml(b, mode)

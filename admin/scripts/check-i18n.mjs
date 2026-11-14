#!/usr/bin/env node
// Checks the translations of the interface: `npm run check:i18n`.
//   catalogues   admin/src/i18n/<locale>/<area>.json and packages/cms/translations/itk_<area>.<locale>.yaml: every area in en, ru, kk, the same keys, the same {params}, the plural forms
//                each language needs (a key with forms in one language has them in all), no empty value; the YAML holds one line per key with the value in single quotes
//   usage        every key of t('…') (JS, Vue), I18n::t('…') (PHP) and itk_t('…') (Twig) is in the catalogues; a key nobody uses is a warning
//   Cyrillic     outside comments in admin/src (except i18n, mock, *.test.*), packages/cms/src, packages/cms/templates and packages/cms/public/preview.js
// Options:
//   --area=<name>             only this area: its catalogues and its keys, no search for Cyrillic
//   --strict                  a warning (a key nobody uses) fails the check too; CI runs it so
//   --warn-cyrillic=<path>    Cyrillic only under this path (file or folder, from the root of the repository, repeat or separate by commas), line by line and as a warning, for work area by area
//
// A key put together at run time is seen by the literal part: t(`area.status_${x}`), t('area.status_' + x), I18n::t('area.status_' . $x), or any string that holds the whole key.
// Anything else: a comment `i18n-dynamic: area.status_` next to the code.
// Cyrillic that belongs in the code: a comment `i18n-ignore` on the line, or an entry in check-i18n.allow.json ({ "path": ["regular expression of the line"] }).
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const ROOT = resolve(HERE, '../..')
const LOCALES = ['en', 'ru', 'kk']
const JS_CATALOGS = 'admin/src/i18n'
const PHP_CATALOGS = 'packages/cms/translations'
const SOURCES = ['admin/src', 'packages/cms/src', 'packages/cms/templates', 'packages/cms/public/preview.js']
/** The categories of Intl.PluralRules a text with forms must have, and the number of forms of a Symfony message. */
const JS_PLURALS = { en: ['one', 'other'], ru: ['one', 'few', 'many', 'other'], kk: ['other'] }
const PHP_PLURALS = { en: 2, ru: 3, kk: 2 }
const KEY = /^[a-z0-9_]+\.[a-z0-9_]+(\.[a-z0-9_]+)*$/
const CYRILLIC = /[Ѐ-ӿ]/
const WORDS_BEFORE_REGEX = new Set(['return', 'typeof', 'case', 'in', 'of', 'delete', 'void', 'throw', 'new', 'else', 'do', 'yield', 'await'])

/** Puts spaces in place of a piece of text and keeps its line breaks, so line numbers stay true. */
const blank = (text) => text.replace(/[^\n]/g, ' ')

/** The code of a JS file with its comments blanked. Strings, templates and regular expressions stay as they are. */
export function stripJs(source) {
  let out = ''
  let i = 0
  let last = '' // the last character of code that is not a space, for telling a regular expression from a division

  const lastWord = () => /([A-Za-z_$][\w$]*)$/.exec(out.trimEnd())?.[1] ?? ''
  const regexMayStart = () => {
    if (last === '' || '(,=:[!&|?{};+-*%<>~^'.includes(last)) return true
    return /[\w$]/.test(last) && WORDS_BEFORE_REGEX.has(lastWord())
  }
  const copyQuoted = (quote) => {
    const start = i++
    while (i < source.length && source[i] !== quote && source[i] !== '\n') i += source[i] === '\\' ? 2 : 1
    i = Math.min(i + 1, source.length)
    out += source.slice(start, i)
    last = quote
  }
  const copyRegex = () => {
    const start = i++
    let inClass = false
    while (i < source.length && source[i] !== '\n' && (inClass || source[i] !== '/')) {
      if (source[i] === '\\') i++
      else if (source[i] === '[') inClass = true
      else if (source[i] === ']') inClass = false
      i++
    }
    i = Math.min(i + 1, source.length)
    out += source.slice(start, i)
    last = '/'
  }
  const copyTemplate = () => {
    out += source[i++]
    while (i < source.length && source[i] !== '`') {
      if (source[i] === '\\') {
        out += source.slice(i, i + 2)
        i += 2
      } else if (source[i] === '$' && source[i + 1] === '{') {
        out += '${'
        i += 2
        last = '{'
        copyCode('}')
        out += source[i++] ?? ''
      } else {
        out += source[i++]
      }
    }
    out += source[i++] ?? ''
    last = '`'
  }
  const copyCode = (until) => {
    let depth = 0
    while (i < source.length) {
      const c = source[i]
      const next = source[i + 1]
      if (c === '/' && next === '/') {
        const end = source.indexOf('\n', i)
        const stop = end < 0 ? source.length : end
        out += blank(source.slice(i, stop))
        i = stop
      } else if (c === '/' && next === '*') {
        const end = source.indexOf('*/', i + 2)
        const stop = end < 0 ? source.length : end + 2
        out += blank(source.slice(i, stop))
        i = stop
      } else if (c === '"' || c === "'") {
        copyQuoted(c)
      } else if (c === '`') {
        copyTemplate()
      } else if (c === '/' && regexMayStart()) {
        copyRegex()
      } else {
        if (until && c === '{') depth++
        if (until && c === until) {
          if (depth === 0) return
          depth--
        }
        out += c
        i++
        if (!/\s/.test(c)) last = c
      }
    }
  }
  copyCode(null)
  return out
}

/** A Vue file: the script without comments, the template without HTML comments, the style blanked. */
export function stripVue(source) {
  return source.replace(/(<script\b[^>]*>)([\s\S]*?)(<\/script>)|(<style\b[^>]*>)([\s\S]*?)(<\/style>)|<!--[\s\S]*?-->/g, (whole, open, code, close, styleOpen, style) => {
    if (open) return open + stripJs(code) + close
    return blank(whole)
  })
}

/** The code of a PHP file with its comments blanked. An attribute #[…] is code; strings and heredocs stay. */
export function stripPhp(source) {
  let out = ''
  let i = 0
  while (i < source.length) {
    const c = source[i]
    const next = source[i + 1]
    if ((c === '/' && next === '/') || (c === '#' && next !== '[')) {
      const end = source.indexOf('\n', i)
      const stop = end < 0 ? source.length : end
      out += blank(source.slice(i, stop))
      i = stop
    } else if (c === '/' && next === '*') {
      const end = source.indexOf('*/', i + 2)
      const stop = end < 0 ? source.length : end + 2
      out += blank(source.slice(i, stop))
      i = stop
    } else if (c === '"' || c === "'") {
      const start = i++
      while (i < source.length && source[i] !== c) i += source[i] === '\\' ? 2 : 1
      i = Math.min(i + 1, source.length)
      out += source.slice(start, i)
    } else if (source.startsWith('<<<', i)) {
      const heredoc = /^<<<\s*['"]?(\w+)['"]?\n/.exec(source.slice(i, i + 200))
      if (heredoc) {
        const end = new RegExp(`^[ \\t]*${heredoc[1]}\\b`, 'm')
        const body = source.slice(i + heredoc[0].length)
        const match = end.exec(body)
        const stop = match ? i + heredoc[0].length + match.index + match[0].length : source.length
        out += source.slice(i, stop)
        i = stop
      } else {
        out += c
        i++
      }
    } else {
      out += c
      i++
    }
  }
  return out
}

/** A Twig template without {# #} and <!-- --> comments. */
export const stripTwig = (source) => source.replace(/\{#[\s\S]*?#\}|<!--[\s\S]*?-->/g, blank)

const STRIP = { '.js': stripJs, '.mjs': stripJs, '.vue': stripVue, '.php': stripPhp, '.twig': stripTwig }

/** {name} of a text, sorted. */
export const paramsOf = (text) => [...new Set([...String(text).matchAll(/\{(\w+)\}/g)].map((m) => m[1]))].sort()

/** Flat YAML of the catalogues: `'area.key': 'text'`, one per line, '' for a quote inside. Answers { messages, errors }. */
export function parseCatalogYaml(text) {
  const messages = {}
  const errors = []
  text.split('\n').forEach((line, index) => {
    if (!line.trim() || line.trimStart().startsWith('#')) return
    const m = /^(?:'((?:[^']|'')+)'|([a-z0-9_.]+)):\s*'((?:[^']|'')*)'\s*$/.exec(line)
    if (!m) {
      errors.push(`line ${index + 1}: expected 'key': 'text' on one line`)
      return
    }
    const key = (m[1] ?? m[2]).replaceAll("''", "'")
    if (key in messages) errors.push(`line ${index + 1}: "${key}" is there twice`)
    messages[key] = m[3].replaceAll("''", "'")
  })
  return { messages, errors }
}

/** What is wrong with the texts of one area. `catalogs` is { locale: { key: value } }; `kind` knows a value: `problem(locale, value)` what is wrong with it, `hasForms(value)`, `params(value)` its {names}. */
function checkParity(area, catalogs, label, kind) {
  const errors = []
  const all = new Set(LOCALES.flatMap((locale) => Object.keys(catalogs[locale] ?? {})))
  for (const key of all) {
    if (!KEY.test(key)) errors.push(`${label}: "${key}" is not a key of the form area.snake_case`)
    if (!key.startsWith(`${area}.`)) errors.push(`${label}: "${key}" does not start with its area "${area}."`)
    const seen = {}
    const withForms = LOCALES.filter((locale) => catalogs[locale] && key in catalogs[locale] && kind.hasForms(catalogs[locale][key]))
    for (const locale of LOCALES) {
      const catalog = catalogs[locale]
      if (!catalog) continue
      if (!(key in catalog)) {
        errors.push(`${label}: "${key}" is missing in ${locale}`)
        continue
      }
      seen[locale] = kind.params(catalog[key]).join(',')
      const problem = kind.problem(locale, catalog[key]) ?? (withForms.length && !withForms.includes(locale) ? `a text without plural forms, ${withForms.join(', ')} has them` : null)
      if (problem) errors.push(`${label}: "${key}" in ${locale}: ${problem}`)
    }
    if (new Set(Object.values(seen)).size > 1) errors.push(`${label}: "${key}" has different parameters: ${LOCALES.map((l) => `${l} {${seen[l] ?? ''}}`).join(', ')}`)
  }
  for (const locale of LOCALES) if (!catalogs[locale]) errors.push(`${label}: no ${locale} file`)
  return errors
}

const jsValueProblem = (locale, value) => {
  if (typeof value === 'string') return value.trim() ? null : 'empty value'
  if (!value || typeof value !== 'object') return 'neither a text nor forms'
  const missing = JS_PLURALS[locale].filter((form) => !String(value[form] ?? '').trim())
  return missing.length ? `no form ${missing.join(', ')}` : null
}
const jsParams = (value) => paramsOf(typeof value === 'string' ? value : Object.values(value ?? {}).join(' '))
const jsHasForms = (value) => Boolean(value) && typeof value === 'object'

const phpHasForms = (value) => value.includes('{count}') && value.includes('|')
const phpValueProblem = (locale, value) => {
  if (!value.trim()) return 'empty value'
  if (!phpHasForms(value)) return null
  const forms = value.split('|')
  if (forms.length !== PHP_PLURALS[locale]) return `${forms.length} forms, ${PHP_PLURALS[locale]} expected`
  if (locale === 'kk' && new Set(forms).size > 1) return 'Symfony always takes the first form in Kazakh: both forms must be the same'
  if (forms.some((form) => /^\w+:/.test(form.trim()))) return 'a form starts with «word:», Symfony cuts that off: reorder the text'
  return null
}

export const checkJsArea = (area, catalogs) => checkParity(area, catalogs, `${JS_CATALOGS} ${area}`, { problem: jsValueProblem, hasForms: jsHasForms, params: jsParams })
export const checkPhpArea = (area, catalogs) => checkParity(area, catalogs, `${PHP_CATALOGS} ${area}`, { problem: phpValueProblem, hasForms: phpHasForms, params: paramsOf })

/** The catalogues on disk by area: { js: { area: { locale: messages } }, php: …, errors }. */
function readCatalogs() {
  const js = {}
  const php = {}
  const errors = []
  for (const locale of LOCALES) {
    const dir = join(ROOT, JS_CATALOGS, locale)
    if (!existsSync(dir)) continue
    for (const file of readdirSync(dir).filter((f) => f.endsWith('.json'))) {
      const area = file.slice(0, -5)
      try {
        ;(js[area] ??= {})[locale] = JSON.parse(readFileSync(join(dir, file), 'utf8'))
      } catch (e) {
        errors.push(`${JS_CATALOGS}/${locale}/${file}: ${e.message}`)
      }
    }
  }
  for (const file of existsSync(join(ROOT, PHP_CATALOGS)) ? readdirSync(join(ROOT, PHP_CATALOGS)) : []) {
    const m = /^itk_(.+)\.([a-z]+)\.yaml$/.exec(file)
    if (!m) continue
    const parsed = parseCatalogYaml(readFileSync(join(ROOT, PHP_CATALOGS, file), 'utf8'))
    ;(php[m[1]] ??= {})[m[2]] = parsed.messages
    errors.push(...parsed.errors.map((e) => `${PHP_CATALOGS}/${file}: ${e}`))
  }
  return { js, php, errors }
}

function* walk(path) {
  if (!existsSync(path)) return
  if (statSync(path).isFile()) {
    yield path
    return
  }
  for (const name of readdirSync(path)) {
    if (name !== 'node_modules') yield* walk(join(path, name))
  }
}

const toRepoPath = (path) => relative(ROOT, path).split(sep).join('/')
const isTranslatedSource = (path) => path.endsWith('.js') || path.endsWith('.vue') || path.endsWith('.php') || path.endsWith('.twig')
const noCyrillicRule = (path) => !/^admin\/src\/(i18n|mock)\//.test(path) && !/\.test\.[a-z]+$/.test(path)

function readAllowList() {
  const file = join(HERE, 'check-i18n.allow.json')
  if (!existsSync(file)) return {}
  return Object.fromEntries(Object.entries(JSON.parse(readFileSync(file, 'utf8'))).map(([path, patterns]) => [path, patterns.map((p) => new RegExp(p))]))
}

/** The lines of code with Cyrillic in one file: [{ line, text }]. */
export function cyrillicLines(source, stripped, allowed = []) {
  const raw = source.split('\n')
  return stripped.split('\n').flatMap((code, index) => {
    if (!CYRILLIC.test(code) || raw[index].includes('i18n-ignore') || allowed.some((pattern) => pattern.test(raw[index]))) return []
    return [{ line: index + 1, text: code.trim() }]
  })
}

const KEY_CALLS = {
  js: [/\bt\(\s*(['"`])([a-z0-9_]+\.[a-z0-9_.]+)\1/g],
  php: [/\bI18n::t\(\s*(['"])([a-z0-9_]+\.[a-z0-9_.]+)\1/g, /\bitk_t\(\s*(['"])([a-z0-9_]+\.[a-z0-9_.]+)\1/g],
}
const KEY_LIKE_STRING = /(['"`])([a-z0-9_]+\.[a-z0-9_.]+)\1/g
const DYNAMIC_KEY = /(['"`])([a-z0-9_]+\.[a-z0-9_.]*)(?:\$\{|\1\s*[.+~])/g
const DYNAMIC_NOTE = /i18n-dynamic:\s*([a-z0-9_]+\.[a-z0-9_.]*)/g

/** What the sources say about keys. */
function scanSources(allow) {
  const used = { js: new Map(), php: new Map() }
  const mentioned = { js: new Set(), php: new Set() }
  const prefixes = { js: new Set(), php: new Set() }
  const cyrillic = new Map()
  for (const root of SOURCES) {
    for (const file of walk(join(ROOT, root))) {
      const path = toRepoPath(file)
      if (!isTranslatedSource(path) || path.startsWith(`${JS_CATALOGS}/`)) continue
      const source = readFileSync(file, 'utf8')
      const strip = STRIP[path.slice(path.lastIndexOf('.'))]
      const code = strip(source)
      const kind = path.endsWith('.php') || path.endsWith('.twig') ? 'php' : 'js'
      for (const pattern of KEY_CALLS[kind]) {
        for (const m of code.matchAll(pattern)) {
          if (!used[kind].has(m[2])) used[kind].set(m[2], path)
        }
      }
      if (!/\.test\.[a-z]+$/.test(path)) {
        for (const m of code.matchAll(KEY_LIKE_STRING)) mentioned[kind].add(m[2])
        for (const m of code.matchAll(DYNAMIC_KEY)) prefixes[kind].add(m[2])
      }
      for (const m of source.matchAll(DYNAMIC_NOTE)) prefixes[kind].add(m[1])
      if (noCyrillicRule(path)) {
        const found = cyrillicLines(source, code, allow[path])
        if (found.length) cyrillic.set(path, found)
      }
    }
  }
  return { used, mentioned, prefixes, cyrillic }
}

function parseArgs(argv) {
  const options = { area: null, cyrillicPaths: [], strict: false }
  for (const arg of argv) {
    if (arg.startsWith('--area=')) options.area = arg.slice(7)
    else if (arg === '--strict') options.strict = true
    else if (arg.startsWith('--warn-cyrillic=')) options.cyrillicPaths.push(...arg.slice(16).split(',').filter(Boolean).map((p) => p.replace(/\/+$/, '')))
    else throw new Error(`Unknown option ${arg}`)
  }
  return options
}

function main() {
  const options = parseArgs(process.argv.slice(2))
  const { js, php, errors: catalogErrors } = readCatalogs()
  const errors = [...catalogErrors]
  const warnings = []
  const inArea = (key) => !options.area || key.startsWith(`${options.area}.`)

  for (const [areas, check] of [[js, checkJsArea], [php, checkPhpArea]]) {
    for (const area of Object.keys(areas)) {
      if (options.area && area !== options.area) continue
      errors.push(...check(area, areas[area]))
    }
  }

  const { used, mentioned, prefixes, cyrillic } = scanSources(readAllowList())
  for (const [kind, areas] of [['js', js], ['php', php]]) {
    const known = new Set(Object.values(areas).flatMap((catalogs) => Object.keys(catalogs.en ?? {})))
    for (const [key, path] of used[kind]) {
      if (inArea(key) && !known.has(key)) errors.push(`${path}: "${key}" is not in the catalogues`)
    }
    for (const key of known) {
      if (!inArea(key) || used[kind].has(key) || mentioned[kind].has(key)) continue
      if (![...prefixes[kind]].some((prefix) => key.startsWith(prefix))) warnings.push(`unused key: ${key} (${kind === 'js' ? 'admin' : 'backend'})`)
    }
  }

  const cyrillicCount = [...cyrillic.values()].reduce((sum, lines) => sum + lines.length, 0)
  if (options.cyrillicPaths.length) {
    let shown = 0
    for (const [path, lines] of cyrillic) {
      if (!options.cyrillicPaths.some((p) => path === p || path.startsWith(`${p}/`))) continue
      for (const { line, text } of lines) {
        console.log(`cyrillic ${path}:${line}: ${text.slice(0, 140)}`)
        shown++
      }
    }
    warnings.push(`${shown} line(s) with Cyrillic under ${options.cyrillicPaths.join(', ')}`)
  } else if (!options.area) {
    for (const [path, lines] of cyrillic) errors.push(`${path}: ${lines.length} line(s) with Cyrillic, first at ${lines[0].line}`)
    if (cyrillicCount) errors.push(`Cyrillic outside comments: ${cyrillicCount} line(s) in ${cyrillic.size} file(s)`)
  }

  warnings.forEach((w) => console.warn(`warning: ${w}`))
  errors.forEach((e) => console.error(`error: ${e}`))
  console.log(`check-i18n: ${errors.length} error(s), ${warnings.length} warning(s)`)
  process.exitCode = errors.length || (options.strict && warnings.length) ? 1 : 0
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main()

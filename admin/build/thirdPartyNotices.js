// Vite plugin: THIRD_PARTY_NOTICES.txt next to the built admin (packages/cms/public/admin).
// The notices list the npm packages that the build really pulled in (the module graph of Rollup, not the whole of package.json), each with its license text,
// so the licenses are kept where the admin is shipped. The file has no dates and a fixed order: a rebuild with the same lock file changes nothing
// (CI runs `git diff --exit-code` on the built admin).
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'

export const FILE = 'THIRD_PARTY_NOTICES.txt'

const MIT = `Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.`

/** The npm package a module id belongs to: { name, dir } (the last node_modules in the path wins), or null for own sources and virtual modules. */
export function packageOf(id) {
  const path = id.split('?')[0]
  if (path.startsWith('\0')) return null
  const at = path.lastIndexOf('/node_modules/')
  if (at === -1) return null
  const rest = path.slice(at + '/node_modules/'.length).split('/')
  const name = rest[0].startsWith('@') ? rest.slice(0, 2).join('/') : rest[0]
  if (!name || name === '@') return null
  return { name, dir: path.slice(0, at + '/node_modules/'.length) + name }
}

/** One entry per package out of the module ids, sorted by name. */
export function collectPackages(ids) {
  const found = new Map()
  for (const id of ids) {
    const pkg = packageOf(id)
    if (pkg && !found.has(pkg.dir)) found.set(pkg.dir, pkg)
  }
  return [...found.values()].sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0) || (a.dir < b.dir ? -1 : 1))
}

function licenseName(json) {
  if (typeof json.license === 'string') return json.license
  if (json.license && typeof json.license.type === 'string') return json.license.type
  if (Array.isArray(json.licenses)) return json.licenses.map((l) => (typeof l === 'string' ? l : l.type)).filter(Boolean).join(' OR ')
  return ''
}

function repositoryUrl(json) {
  const raw = typeof json.repository === 'string' ? json.repository : json.repository?.url
  if (!raw) return ''
  const short = raw.match(/^github:(.+)$/) || raw.match(/^([\w.-]+\/[\w.-]+)$/)
  if (short) return `https://github.com/${short[1]}`
  return raw.replace(/^git\+/, '').replace(/^git:\/\//, 'https://').replace(/^ssh:\/\/git@/, 'https://').replace(/\.git$/, '')
}

function licenseText(dir, json) {
  const file = readdirSync(dir).filter((f) => /^(licen[cs]e|copying)(\.(md|txt))?$/i.test(f)).sort()[0]
  if (file) return readFileSync(join(dir, file), 'utf8').replace(/\r\n/g, '\n').trim()
  // a package without a license file: the standard text of the MIT license, with the copyright line from the head of its main file, or its author
  if (licenseName(json) !== 'MIT') return ''
  const main = join(dir, json.main || 'index.js')
  const head = existsSync(main) ? readFileSync(main, 'utf8').split('\n').slice(0, 40) : []
  const line = head.find((l) => /copyright/i.test(l))
  const author = typeof json.author === 'string' ? json.author.replace(/\s*[<(].*$/, '') : json.author?.name
  const notice = line ? line.replace(/^[\s/*#-]+/, '').trim() : author ? `Author: ${author}` : 'The package names no copyright holder.'
  return `${notice}\n\n${MIT}`
}

/** What the notices say about one package: { name, version, license, repository, text }. */
export function readPackage({ name, dir }) {
  const json = JSON.parse(readFileSync(join(dir, 'package.json'), 'utf8'))
  return { name, version: json.version || '', license: licenseName(json), repository: repositoryUrl(json), text: licenseText(dir, json) }
}

export function renderNotices(packages) {
  const rule = '-'.repeat(78)
  const out = [
    'Third-party notices of the ITK CMS admin',
    '',
    'The admin in this directory is built from the sources in admin/ of the ITK CMS repository (MIT license, see LICENSE) together with the open-source',
    'packages below. The Golos Text font files (assets/golos-text-*.woff2) come from @fontsource-variable/golos-text and are under its license, the SIL Open',
    'Font License 1.1. This file is generated by `npm run build` in admin/ (admin/build/thirdPartyNotices.js); do not edit it by hand.',
    '',
    rule,
    '',
  ]
  for (const p of packages) {
    out.push(`${p.name} ${p.version}`, `License: ${p.license || 'not stated in the package'}`)
    if (p.repository) out.push(p.repository)
    out.push('', p.text || 'The package ships no license text.', '', rule, '')
  }
  return out.join('\n')
}

export default function thirdPartyNotices() {
  return {
    name: 'itk-third-party-notices',
    apply: 'build',
    writeBundle(options) {
      const packages = collectPackages(this.getModuleIds()).map(readPackage)
      writeFileSync(join(options.dir, FILE), renderNotices(packages))
    },
  }
}

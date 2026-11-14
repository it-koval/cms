import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { afterAll, describe, expect, it } from 'vitest'
import { collectPackages, packageOf, readPackage, renderNotices } from './thirdPartyNotices.js'

describe('packageOf finds the npm package a module belongs to', () => {
  it('plain and scoped packages, the last node_modules wins', () => {
    expect(packageOf('/w/admin/node_modules/vue/dist/vue.runtime.esm-bundler.js')).toEqual({ name: 'vue', dir: '/w/admin/node_modules/vue' })
    expect(packageOf('/w/admin/node_modules/@tiptap/core/dist/index.js')).toEqual({ name: '@tiptap/core', dir: '/w/admin/node_modules/@tiptap/core' })
    expect(packageOf('/w/node_modules/a/node_modules/b/index.js')).toEqual({ name: 'b', dir: '/w/node_modules/a/node_modules/b' })
  })
  it('virtual ids, queries and own sources are not packages', () => {
    expect(packageOf('\0plugin-vue:export-helper')).toBeNull()
    expect(packageOf('/w/admin/src/main.js')).toBeNull()
    expect(packageOf('/w/admin/node_modules/@fontsource-variable/golos-text/wght.css?used')).toEqual({
      name: '@fontsource-variable/golos-text',
      dir: '/w/admin/node_modules/@fontsource-variable/golos-text',
    })
  })
})

describe('collectPackages', () => {
  it('one entry per package, sorted by name', () => {
    const ids = ['/w/node_modules/b/x.js', '/w/node_modules/a/y.js', '/w/node_modules/b/z.js', '/w/src/own.js']
    expect(collectPackages(ids).map((p) => p.name)).toEqual(['a', 'b'])
  })
})

describe('readPackage', () => {
  const root = mkdtempSync(join(tmpdir(), 'itk-notices-'))
  afterAll(() => rmSync(root, { recursive: true, force: true }))
  const make = (name, files) => {
    const dir = join(root, 'node_modules', name)
    mkdirSync(dir, { recursive: true })
    for (const [file, body] of Object.entries(files)) writeFileSync(join(dir, file), body)
    return { name, dir }
  }

  it('takes the version, the license and its text from the package', () => {
    const pkg = readPackage(make('with-file', {
      'package.json': JSON.stringify({ name: 'with-file', version: '1.2.3', license: 'ISC', repository: { type: 'git', url: 'git+https://example.com/a/b.git' } }),
      LICENSE: 'ISC text\n',
    }))
    expect(pkg).toMatchObject({ name: 'with-file', version: '1.2.3', license: 'ISC', repository: 'https://example.com/a/b', text: 'ISC text' })
  })

  it('an old-style licenses array and a string repository are read too', () => {
    const pkg = readPackage(make('old-style', {
      'package.json': JSON.stringify({ name: 'old-style', version: '0.1.0', licenses: [{ type: 'MIT' }, { type: 'Apache-2.0' }], repository: 'github:o/r' }),
      'LICENSE.md': 'text\n',
    }))
    expect(pkg.license).toBe('MIT OR Apache-2.0')
    expect(pkg.repository).toBe('https://github.com/o/r')
  })

  it('no license file: the copyright line of the main file and the standard MIT text', () => {
    const pkg = readPackage(make('no-file', {
      'package.json': JSON.stringify({ name: 'no-file', version: '2.0.0', license: 'MIT', main: 'index.js' }),
      'index.js': '//\n// Copyright (c) 2009 Somebody\n//\nvar x = 1\n',
    }))
    expect(pkg.text).toContain('Copyright (c) 2009 Somebody')
    expect(pkg.text).toContain('Permission is hereby granted, free of charge')
  })

  it('no license file and no copyright line: the author, or a statement that the package names no holder', () => {
    const byAuthor = readPackage(make('by-author', { 'package.json': JSON.stringify({ name: 'by-author', version: '1.0.0', license: 'MIT', author: { name: 'Ann Lee', email: 'a@example.com' } }) }))
    expect(byAuthor.text.startsWith('Author: Ann Lee\n\nPermission is hereby granted')).toBe(true)
    const nobody = readPackage(make('nobody', { 'package.json': JSON.stringify({ name: 'nobody', version: '1.0.0', license: 'MIT' }) }))
    expect(nobody.text.startsWith('The package names no copyright holder.')).toBe(true)
  })

  it('no license file and not MIT: says so instead of inventing a text', () => {
    const pkg = readPackage(make('bare', { 'package.json': JSON.stringify({ name: 'bare', version: '1.0.0', license: 'BSD-3-Clause' }) }))
    expect(pkg.text).toBe('')
  })
})

describe('renderNotices', () => {
  const a = { name: 'a', version: '1.0.0', license: 'MIT', repository: 'https://example.com/a', text: 'Copyright a' }
  const b = { name: 'b', version: '2.0.0', license: 'ISC', repository: '', text: '' }

  it('lists every package with its license; a missing text is stated', () => {
    const out = renderNotices([a, b])
    expect(out).toContain('a 1.0.0\nLicense: MIT\nhttps://example.com/a\n\nCopyright a\n')
    expect(out).toContain('b 2.0.0\nLicense: ISC\n')
    expect(out).toContain('The package ships no license text.')
  })

  it('has no dates and ends with one newline, so a rebuild changes nothing', () => {
    const out = renderNotices([a, b])
    expect(out).toBe(renderNotices([a, b]))
    expect(out.endsWith('\n')).toBe(true)
    expect(out.endsWith('\n\n')).toBe(false)
    expect(out).not.toMatch(/\b20\d\d-\d\d-\d\d\b/)
  })
})

describe('the real dependencies', () => {
  it('the font and the editor are in the notices of a build that imports them', () => {
    const root = new URL('../node_modules/', import.meta.url).pathname
    const packages = collectPackages([`${root}@fontsource-variable/golos-text/wght.css`, `${root}vue/dist/vue.runtime.esm-bundler.js`]).map(readPackage)
    const out = renderNotices(packages)
    expect(out).toContain('@fontsource-variable/golos-text')
    expect(out).toContain('OFL-1.1')
    expect(out).toContain('SIL OPEN FONT LICENSE')
    expect(out).toMatch(/\nvue \d+\.\d+\.\d+\nLicense: MIT\n/)
  })
})

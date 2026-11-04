import { describe, expect, it } from 'vitest'
import { checkJsArea, checkPhpArea, cyrillicLines, paramsOf, parseCatalogYaml, stripJs, stripPhp, stripTwig, stripVue } from './check-i18n.mjs'

const found = (source, strip) => cyrillicLines(source, strip(source)).map((l) => l.line)

describe('comments are not code', () => {
  it('JS: comments go, strings, templates and regular expressions stay', () => {
    expect(found("// Привет\nconst a = 1 /* да */\nconst b = 'Привет'\n", stripJs)).toEqual([3])
    expect(found('const u = "http://x" + `Привет ${a /* нет */ + \'да\'}`\n', stripJs)).toEqual([1])
    expect(found("const url = 'https://site.test/' // ссылка\n", stripJs)).toEqual([])
  })

  it('JS: a regular expression with a quote or slashes does not start a string or a comment', () => {
    expect(found("const r = /^https?:\\/\\//.test(a) // ссылка\n", stripJs)).toEqual([])
    expect(found("const r = /['\"]/g\n// коммент\nconst s = 'да'\n", stripJs)).toEqual([3])
    expect(found('const half = a / 2 // пол\nconst s = b / 2\n', stripJs)).toEqual([])
    expect(found("const r = /[а-я/]+/\n", stripJs)).toEqual([1])
  })

  it('Vue: the script, the template and the style each by their rules', () => {
    const source = "<script setup>\n// Коммент\nconst a = 'Текст'\n</script>\n<template>\n  <!-- Коммент -->\n  <b>Текст</b>\n</template>\n<style>\n/* Коммент */\n</style>\n"
    expect(found(source, stripVue)).toEqual([3, 7])
  })

  it('PHP: an attribute is code, comments of all three kinds go', () => {
    const source = "<?php\n// Коммент\n# Коммент\n/** Коммент */\n#[Route(name: 'Текст')]\n$a = 'Текст'; // Коммент\n$b = 'http://x'; // Коммент\n"
    expect(found(source, stripPhp)).toEqual([5, 6])
  })

  it('PHP: a heredoc is text', () => {
    expect(found("<?php\n$a = <<<'TXT'\n    Привет // да\n    TXT;\n// Коммент\n", stripPhp)).toEqual([3])
  })

  it('Twig: comments go', () => {
    expect(found('{# Коммент #}\n<!-- Коммент -->\n<b>Текст</b>\n', stripTwig)).toEqual([3])
  })

  it('a line marked i18n-ignore and a line of the allowed list are left alone', () => {
    const source = "const a = 'Русский' // i18n-ignore\nconst b = 'Қазақша'\n"
    expect(cyrillicLines(source, stripJs(source)).map((l) => l.line)).toEqual([2])
    expect(cyrillicLines(source, stripJs(source), [/Қазақша/])).toEqual([])
  })
})

describe('catalogues', () => {
  it('reads the flat YAML of the bundle', () => {
    const { messages, errors } = parseCatalogYaml("# note\n'a.b': 'It''s {name}'\nc.d: 'x'\n")
    expect(errors).toEqual([])
    expect(messages).toEqual({ 'a.b': "It's {name}", 'c.d': 'x' })
    expect(paramsOf(messages['a.b'])).toEqual(['name'])
  })

  it('a value without single quotes, on two lines or a key twice is an error', () => {
    expect(parseCatalogYaml('a.b: "x"\n').errors).toHaveLength(1)
    expect(parseCatalogYaml("a.b: 'x\n  y'\n").errors).toHaveLength(2)
    expect(parseCatalogYaml("a.b: 'x'\na.b: 'y'\n").errors).toHaveLength(1)
  })
})

describe('plural forms are the same kind of value in every language', () => {
  const JS = { en: { one: '{count} page', other: '{count} pages' }, ru: { one: '{count} страница', few: '{count} страницы', many: '{count} страниц', other: '{count} страницы' }, kk: { other: '{count} бет' } }
  const PHP = { en: '{count} page|{count} pages', ru: '{count} страница|{count} страницы|{count} страниц', kk: '{count} бет|{count} бет' }
  const js = (values) => checkJsArea('a', Object.fromEntries(Object.entries({ ...JS, ...values }).map(([locale, value]) => [locale, { 'a.pages': value }])))
  const php = (values) => checkPhpArea('a', Object.fromEntries(Object.entries({ ...PHP, ...values }).map(([locale, value]) => [locale, { 'a.pages': value }])))

  it('forms in all three languages are fine, and so is a text with {count} without forms in all three', () => {
    expect(js({})).toEqual([])
    expect(php({})).toEqual([])
    expect(js({ en: 'Found: {count}', ru: 'Найдено: {count}', kk: 'Табылды: {count}' })).toEqual([])
    expect(php({ en: 'Found: {count}', ru: 'Найдено: {count}', kk: 'Табылды: {count}' })).toEqual([])
  })

  it.each(['en', 'ru', 'kk'])('JS: a plain text in %s next to forms is an error', (locale) => {
    expect(js({ [locale]: '{count} x' })).toEqual([expect.stringContaining(`"a.pages" in ${locale}: a text without plural forms`)])
  })

  it.each(['en', 'ru', 'kk'])('PHP: no forms in %s next to forms is an error', (locale) => {
    expect(php({ [locale]: '{count} x' })).toEqual([expect.stringContaining(`"a.pages" in ${locale}: a text without plural forms`)])
  })

  it('PHP: forms without {count} are no forms, the language is named along with the different parameters', () => {
    const errors = php({ en: 'page|pages' })
    expect(errors).toContainEqual(expect.stringContaining('"a.pages" in en: a text without plural forms'))
    expect(errors).toContainEqual(expect.stringContaining('different parameters'))
  })

  it('an empty value is reported as that, not as missing forms too', () => {
    for (const errors of [js({ en: '' }), php({ en: ' ' })]) {
      expect(errors).toContainEqual(expect.stringContaining('"a.pages" in en: empty value'))
      expect(errors.filter((e) => e.includes('without plural forms'))).toEqual([])
    }
  })
})

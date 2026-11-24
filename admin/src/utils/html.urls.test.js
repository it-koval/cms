// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import cases from '../../../packages/cms/tests/Fixtures/html-url-cases.json'
import { sanitizeHtml } from './html.js'

describe('shared HTML URL policy', () => {
  it.each(cases)('$name survives the save round trip', ({ html, expected }) => {
    const saved = sanitizeHtml(html, 'article')
    expect(saved).toBe(expected)
    expect(sanitizeHtml(saved, 'article')).toBe(saved)
  })
})

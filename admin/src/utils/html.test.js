// @vitest-environment jsdom
import { expect, it } from 'vitest'
import examples from '../../../packages/cms/tests/Core/Fixture/html-sanitizer.json'
import { sanitizeHtml } from './html.js'

it.each(examples)('keeps the saved HTML stable for $name', ({ input, mode, expected }) => {
  const html = sanitizeHtml(input, mode)
  expect(html).toBe(expected)
  expect(sanitizeHtml(html, mode)).toBe(expected)
})

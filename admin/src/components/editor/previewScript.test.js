// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import process from 'node:process'
import { JSDOM } from 'jsdom'
import { afterEach, expect, it, vi } from 'vitest'

const script = readFileSync(process.cwd() + '/../packages/cms/public/preview.js', 'utf8')
let dom
const setup = (html, preview = true) => {
  dom = new JSDOM(html, { url: 'http://localhost:3000', runScripts: 'outside-only', pretendToBeVisual: true })
  const post = vi.fn()
  Object.defineProperty(dom.window, 'parent', { configurable: true, value: { postMessage: post } })
  if (preview) dom.window.eval(script)
  return { document: dom.window.document, post }
}
const click = (element) => {
  const event = new dom.window.MouseEvent('click', { bubbles: true, cancelable: true })
  element.dispatchEvent(event)
  return event
}
afterEach(() => dom?.window.close())

it('supports native details activation on a nested summary element', () => {
  const { document } = setup('<details><summary><span>Question</span></summary>Answer</details>', false)
  click(document.querySelector('span'))
  expect(document.querySelector('details').open).toBe(true)
})

it('opens the marked summary field and expands its details with the same element click', () => {
  const { document, post } = setup('<details><summary><span data-f="title" data-sec="faq">Question</span></summary>Answer</details>')
  const event = click(document.querySelector('span'))
  expect(post).toHaveBeenCalledWith({ type: 'itk:focus', sec: 'faq', path: 'title' }, dom.window.location.origin)
  expect(event.defaultPrevented).toBe(false)
  expect(document.querySelector('details').open).toBe(true)
})

it('leaves the default action available to a marked label within a summary', () => {
  const { document } = setup('<details><summary><label data-f="title" data-sec="faq">Question</label></summary>Answer</details>')
  expect(click(document.querySelector('label')).defaultPrevented).toBe(false)
})

it.each(['<a href="/elsewhere">Question</a>', '<button type="button">Question</button>'])('keeps interactive summary content inert: %s', (content) => {
  const { document, post } = setup(`<details><summary><span data-f="title" data-sec="faq">${content}</span></summary>Answer</details>`)
  const event = click(document.querySelector('a,button'))
  expect(event.defaultPrevented).toBe(true)
  expect(document.querySelector('details').open).toBe(false)
  expect(post).toHaveBeenCalledWith({ type: 'itk:focus', sec: 'faq', path: 'title' }, dom.window.location.origin)
})

it('forwards repeater row identities from both text and attribute markers', () => {
  const { document, post } = setup('<span data-f="faq.0.title" data-sec="@collection/team/one" data-row-ids="[&quot;A&quot;]">A</span><img data-f="faq.0.cover" data-sec="@settings" data-row-ids="[&quot;A&quot;]">')
  click(document.querySelector('span'))
  expect(post).toHaveBeenCalledWith({ type: 'itk:focus', sec: '@collection/team/one', path: 'faq.0.title', rows: ['A'] }, dom.window.location.origin)
  click(document.querySelector('img'))
  expect(post).toHaveBeenCalledWith({ type: 'itk:focus', sec: '@settings', path: 'faq.0.cover', rows: ['A'] }, dom.window.location.origin)
})

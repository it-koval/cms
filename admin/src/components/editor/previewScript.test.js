// @vitest-environment jsdom
import { readFileSync } from 'node:fs'
import process from 'node:process'
import { afterEach, expect, it, vi } from 'vitest'

const script = readFileSync(process.cwd() + '/../packages/cms/public/preview.js', 'utf8')
afterEach(() => { delete window.__itkPreview; document.body.innerHTML = ''; document.querySelectorAll('.itk-pv').forEach((node) => node.remove()); vi.restoreAllMocks() })
it('opens a marked summary field when the click target is its nested text node', () => {
  document.body.innerHTML = '<details><summary><span data-f="title" data-sec="faq">Question</span></summary></details>'
  const post = vi.fn()
  const original = window.parent
  Object.defineProperty(window, 'parent', { configurable: true, value: { postMessage: post } })
  window.eval(script)
  const click = new MouseEvent('click', { bubbles: true, cancelable: true })
  document.querySelector('span').firstChild.dispatchEvent(click)
  expect(post).toHaveBeenCalledWith({ type: 'itk:focus', sec: 'faq', path: 'title' }, location.origin)
  expect(click.defaultPrevented).toBe(true)
  Object.defineProperty(window, 'parent', { configurable: true, value: original })
})

import { describe, expect, it } from 'vitest'
import { flatWords } from './pageTemplateRules.js'

describe('flatWords', () => {
  it('speaks of pages for a template without its own wording', () => {
    expect(flatWords('anything')).toMatchObject({ add: 'Добавить страницу', title: 'Новая страница' })
  })

  it('has an example for the article that names no business of any site', () => {
    const w = flatWords('blog_article')
    expect(w.add).toBe('Добавить статью')
    expect(w.ph).toMatch(/^Например: /)
    expect(w.phUrl).toMatch(/^\/[a-z0-9-]+$/)
    expect(w.ph + w.phUrl).not.toMatch(/брейс|breys/i)
  })
})

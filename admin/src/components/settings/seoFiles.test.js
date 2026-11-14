import { describe, expect, it } from 'vitest'
import { canResetToDefault, draftsOf, fileName, isDirty, savePatch } from './seoFiles.js'

const robots = { text: 'User-agent: *\nDisallow: /admin/\n', default: 'User-agent: *\nDisallow: /admin/\n', custom: false, file: false }
const llms = { text: '# Site\n', default: '# Site\n> About\n', custom: true, file: false }
const files = { robots, llms }

describe('seo files', () => {
  it('names the files', () => {
    expect(fileName('robots')).toBe('robots.txt')
    expect(fileName('llms')).toBe('llms.txt')
  })

  it('starts from the text the site serves', () => {
    expect(draftsOf(files)).toEqual({ robots: robots.text, llms: llms.text })
  })

  it('is dirty only when the draft differs from the saved text (line endings do not count)', () => {
    expect(isDirty(robots.text, robots)).toBe(false)
    expect(isDirty('User-agent: *\r\nDisallow: /admin/\r\n', robots)).toBe(false)
    expect(isDirty('Disallow: /\n', robots)).toBe(true)
    expect(isDirty('', null)).toBe(false)
  })

  it('offers «standard» while the field holds anything but the standard text', () => {
    expect(canResetToDefault(robots.text, robots)).toBe(false)
    expect(canResetToDefault('User-agent: *\n', robots)).toBe(true)
    expect(canResetToDefault(llms.text, llms)).toBe(true) // an own text
    expect(canResetToDefault(llms.default, llms)).toBe(false) // already put back, waiting for «Сохранить»
  })

  it('sends only the changed files, and the standard text as null', () => {
    expect(savePatch(draftsOf(files), files)).toEqual({})
    expect(savePatch({ robots: 'Disallow: /\n', llms: llms.text }, files)).toEqual({ robots: 'Disallow: /\n' })
    expect(savePatch({ robots: robots.text, llms: llms.default }, files)).toEqual({ llms: null })
    expect(savePatch({ robots: '', llms: llms.text }, files)).toEqual({ robots: '' })
  })
})

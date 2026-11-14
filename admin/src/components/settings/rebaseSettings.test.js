import { describe, expect, it } from 'vitest'
import { clone } from '@/utils/objects.js'
import { rebaseSettings } from './rebaseSettings.js'

describe('settings edited while a save is in flight', () => {
  it('keeps later input and accepts server normalization for unchanged fields', async () => {
    const draft = { phone: '123', email: '  mail@example.com  ' }
    const sent = clone(draft)
    let reply
    const saved = new Promise((resolve) => { reply = resolve })
      .then((response) => rebaseSettings(response, sent, draft))
    draft.phone = '1234'
    reply({ phone: '123', email: 'mail@example.com', tgReady: true })
    expect(await saved).toEqual({ phone: '1234', email: 'mail@example.com', tgReady: true })
  })

  it('preserves a return to the previous saved value after submitting another value', () => {
    const sent = { phone: 'new number' }
    const current = { phone: 'old number' }
    expect(rebaseSettings({ phone: 'new number' }, sent, current)).toEqual(current)
  })

  it('keeps local additions and removals without sharing nested data with either draft or response', () => {
    const sent = { title: 'old', tags: ['a'], banner: 'remove me' }
    const current = { title: 'old', tags: ['a', 'b'], footer: 'added' }
    const response = { title: 'Old', tags: ['a'], banner: 'remove me', routes: { '*': '123' } }
    const result = rebaseSettings(response, sent, current)
    expect(result).toEqual({ title: 'Old', tags: ['a', 'b'], footer: 'added', routes: { '*': '123' } })
    result.tags.push('c')
    result.routes['*'] = 'other'
    expect(current.tags).toEqual(['a', 'b'])
    expect(response.routes['*']).toBe('123')
  })
})

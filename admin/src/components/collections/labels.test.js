import { describe, expect, it } from 'vitest'
import { changesText, countText, toggleLabelOf, toggleNote } from './labels.js'

describe('toggleNote: the toast after the switch of a row', () => {
  it('names the switch as the collection calls it, not a catalog of one site', () => {
    expect(toggleNote('Кольцо', 'Показывать в каталоге', true)).toBe('«Кольцо»: «Показывать в каталоге» включится после публикации')
    expect(toggleNote('Кольцо', 'Показывать в каталоге', false)).toBe('«Кольцо»: «Показывать в каталоге» выключится после публикации')
  })

  it('has a general word for a collection that names nothing', () => {
    expect(toggleNote('Запись', '', true)).toBe('«Запись»: «Показывать» включится после публикации')
    expect(toggleNote('Запись', undefined, false)).toContain('«Показывать»')
  })
})

describe('toggleLabelOf: how the switch of a row is named', () => {
  const tabs = [{ key: 'main', fields: [{ k: 'title', t: 'text', label: 'Заголовок' }] }, { key: 'more', fields: [{ k: 'pinned', t: 'toggle', label: 'На главной' }] }]

  it('reads the label of the field itself: the server sends only toggleField', () => {
    expect(toggleLabelOf({ toggleField: 'pinned', tabs })).toBe('На главной')
  })

  it('prefers the name the collection gives', () => {
    expect(toggleLabelOf({ toggleField: 'pinned', toggleLabel: 'В подборке', tabs })).toBe('В подборке')
  })

  it('has a general word for a field that is not in the form or has no label', () => {
    expect(toggleLabelOf({ toggleField: 'gone', tabs })).toBe('Показывать')
    expect(toggleLabelOf({ toggleField: 'bare', tabs: [{ key: 'main', fields: [{ k: 'bare', t: 'toggle' }] }] })).toBe('Показывать')
  })
})

describe('counts', () => {
  it('speaks of records and changes in Russian', () => {
    expect(countText('any', 3)).toBe('3 записи')
    expect(changesText(5)).toBe('5 правок')
  })
})

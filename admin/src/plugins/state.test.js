import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ITK_VERSION, pluginAction, pluginEditorActions, pluginField, pluginPanel, pluginPreview, pluginWidgets, registerPlugin, registeredPlugins, resetPlugins, warnTemplateOption } from './state.js'

const comp = (name) => ({ name, render: () => null })
let warn
beforeEach(() => { resetPlugins(); warn = vi.spyOn(console, 'warn').mockImplementation(() => {}) })
afterEach(() => { warn.mockRestore() })

describe('the contract version', () => {
  it('is a semver', () => {
    expect(ITK_VERSION).toMatch(/^\d+\.\d+\.\d+$/)
  })
})

describe('registerPlugin', () => {
  it('registers fields, panels, previews and actions and finds them', () => {
    const f = comp('f'); const p = comp('p'); const v = comp('v'); const run = () => {}
    registerPlugin({ fields: { color: f }, panels: { reports: p }, previews: { tours: v }, actions: { 'tours:export': run, other: { modal: comp('m') } } })
    expect(pluginField('color')).toBe(f)
    expect(pluginPanel('reports')).toBe(p)
    expect(pluginPreview('tours')).toBe(v)
    expect(pluginAction('tours', 'export')).toBe(run)
    expect(pluginAction('tours', 'other').modal).toBeTruthy() // `action` alone is looked up after `resource:action`
    expect(pluginField('none')).toBeNull()
    expect(registeredPlugins()).toHaveLength(1)
  })

  it('lets a later registration win and ignores what is not a plugin', () => {
    registerPlugin(null)
    registerPlugin([])
    registerPlugin('x')
    const a = comp('a'); const b = comp('b')
    registerPlugin({ fields: { color: a } })
    registerPlugin({ fields: { color: b, '': a, empty: null } })
    expect(pluginField('color')).toBe(b)
    expect(pluginField('empty')).toBeNull()
    expect(registeredPlugins()).toHaveLength(2)
  })
})

describe('a component written with the template option', () => {
  it('is reported in the console, once, and not registered', () => {
    const bad = { template: '<b>hi</b>' }
    registerPlugin({ panels: { reports: bad } })
    registerPlugin({ panels: { reports: bad } })
    expect(warn).toHaveBeenCalledTimes(1)
    expect(warn.mock.calls[0][0]).toMatch(/«reports» \(panels\).*template.*render\(\).*README/s)
    expect(pluginPanel('reports')).toBeNull()
  })

  it('is told for every kind of component, and for the modal of an action', () => {
    const bad = { template: '<i/>' }
    registerPlugin({ fields: { a: bad }, previews: { b: bad }, widgets: { c: bad }, actions: { d: { modal: bad } } })
    expect(warn).toHaveBeenCalledTimes(4)
  })

  it('is not told when the component has a render function or a setup', () => {
    expect(warnTemplateOption('panels', 'x', { template: '<i/>', render: () => null })).toBe(false)
    expect(warnTemplateOption('panels', 'x', { setup: () => () => null })).toBe(false)
    expect(warnTemplateOption('panels', 'x', () => null)).toBe(false)
    expect(warn).not.toHaveBeenCalled()
  })
})

describe('widgets of «Сегодня»', () => {
  it('are listed in the order they were registered', () => {
    const a = comp('a'); const b = comp('b')
    registerPlugin({ widgets: { first: a } })
    registerPlugin({ widgets: { second: b } })
    expect(pluginWidgets()).toEqual([{ name: 'first', component: a }, { name: 'second', component: b }])
  })
})

describe('buttons of the editor top bar', () => {
  it('keeps the valid ones in order, then registration order', () => {
    const run = () => {}
    registerPlugin({ editorActions: { late: { label: 'B', run, order: 5 }, early: { label: 'A', run, order: -1, icon: 'sparkle', title: 'Подсказка' }, plain: { label: 'C', run } } })
    expect(pluginEditorActions().map((a) => a.name)).toEqual(['early', 'plain', 'late'])
    const early = pluginEditorActions()[0]
    expect(early).toMatchObject({ label: 'A', icon: 'sparkle', title: 'Подсказка', order: -1, when: null })
    expect(typeof early.run).toBe('function')
  })

  it('skips a button without a label or without run, and says so', () => {
    registerPlugin({ editorActions: { a: { label: '', run() {} }, b: { label: 'B' }, c: 'nope', d: { label: 'D', run() {}, when: () => true } } })
    expect(pluginEditorActions().map((a) => a.name)).toEqual(['d'])
    expect(pluginEditorActions()[0].when()).toBe(true)
    expect(warn).toHaveBeenCalledTimes(3)
  })
})

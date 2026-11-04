// What the plugins of a site have registered (window.ITK.registerPlugin, see registry.js) and how the screens ask for it. No components of the admin are imported here, so the
// screens that read the registry (FieldRenderer, TodayView, EditorTopBar...) and registry.js itself, which imports those screens' building blocks for window.ITK.ui, do not form a cycle.
import { markRaw, shallowReactive } from 'vue'
import { t } from '@/i18n/index.js'

/** The version of the contract of window.ITK (semver). Minor: something was added; major: something a plugin could rely on changed or went. Described in src/README.md, typed in types/itk.d.ts. */
export const ITK_VERSION = '1.1.0'

const state = shallowReactive({ fields: {}, panels: {}, actions: {}, previews: {}, widgets: {}, editorActions: {} })
const registered = []
const warned = new Set()

const isMap = (v) => !!v && typeof v === 'object' && !Array.isArray(v)
const KINDS = ['fields', 'panels', 'previews', 'widgets', 'actions', 'editorActions']

/** An editor button is `{ label, run(ctx), icon?, title?, order?, when?(ctx) }`; anything else is not one. */
const isEditorAction = (v) => isMap(v) && typeof v.label === 'string' && v.label.trim() !== '' && typeof v.run === 'function'

/**
 * A component written with the `template` option has no compiler to turn it into a render function here (the admin ships Vue without it), so it draws nothing and Vue says nothing in production.
 * Say it once, in the console, in development and in production alike. Returns true when the component is such a one.
 */
export function warnTemplateOption(kind, name, component) {
  if (!isMap(component) || typeof component.template !== 'string' || typeof component.render === 'function') return false
  const key = `${kind}:${name}`
  if (!warned.has(key)) {
    warned.add(key)
    console.warn(t('core.plugin_template_option', { name, kind }))
  }
  return true
}

/** Add a plugin: `{ fields, panels, previews, widgets, actions, editorActions }`, each an object name → value. Later registrations win over earlier ones of the same name. A broken entry is skipped. */
export function registerPlugin(plugin) {
  if (!isMap(plugin)) return
  registered.push(plugin)
  KINDS.forEach((kind) => {
    if (!isMap(plugin[kind])) return
    const next = { ...state[kind] }
    Object.entries(plugin[kind]).forEach(([name, value]) => {
      if (!name || value == null) return
      if (kind === 'editorActions') {
        if (isEditorAction(value)) next[name] = value
        else console.warn(t('core.plugin_editor_action_skipped', { name }))
      } else if (kind === 'actions') {
        if (typeof value === 'function') next[name] = value
        else {
          warnTemplateOption(kind, name, value.modal)
          next[name] = markRaw(value)
        }
      } else if (!warnTemplateOption(kind, name, value)) { // a component that cannot draw is not registered: the screen keeps its own fallback
        next[name] = markRaw(value)
      }
    })
    state[kind] = next
  })
}

/** The plugins registered so far, in order (window.ITK.plugins). */
export const registeredPlugins = () => registered

/** The component that draws fields of type `type`, or null (the built-in field types are not here). */
export const pluginField = (type) => state.fields[type] || null
/** The screen registered under `name` (the first part of /x/:name), or null. */
export const pluginPanel = (name) => state.panels[name] || null
/** The record-page card a plugin gave to the collection `key`, or null (the core draws its plain card). */
export const pluginPreview = (key) => state.previews[key] || null
/** The client side of an action: a function `(ctx) => result|void` or an object `{ modal: Component }`, or null. `resource:action` is looked up before `action`. */
export const pluginAction = (resource, action) => state.actions[`${resource}:${action}`] || state.actions[action] || null
/** The widgets of «Сегодня»: [{ name, component }] in the order they were registered. */
export const pluginWidgets = () => Object.entries(state.widgets).map(([name, component]) => ({ name, component }))
/** The buttons of the page editor's top bar: [{ name, label, icon, title, order, run, when }] by `order` (default 0), then in the order they were registered. */
export const pluginEditorActions = () => Object.entries(state.editorActions)
  .map(([name, a], at) => ({
    name, at, label: a.label, run: a.run,
    icon: typeof a.icon === 'string' ? a.icon : '', title: typeof a.title === 'string' ? a.title : '', order: Number.isFinite(a.order) ? a.order : 0, when: typeof a.when === 'function' ? a.when : null,
  }))
  .sort((a, b) => a.order - b.order || a.at - b.at)

/** For tests: forget everything. */
export function resetPlugins() {
  KINDS.forEach((kind) => { state[kind] = {} })
  registered.length = 0
  warned.clear()
}

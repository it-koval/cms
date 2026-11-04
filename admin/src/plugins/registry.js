// The plugin registry: how a package of a site (its own admin script, added with Registry::adminAssets(js: [...]) on the server) extends this admin.
//
//   window.ITK.registerPlugin({
//     fields:  { 'color': ColorField },                       field types: a field of the schema with t: 'color' is drawn by this component
//     panels:  { 'reports': ReportsPanel },                   screens: a menu item (NavItem::make('Отчёты', '/x/reports')) opens #/x/reports with this component
//     actions: { 'tours:export': async (ctx) => {...} },      client side of an action: 'resource:action' or just 'action'
//     previews: { 'tours': TourCard },                         the card on the record page of a collection (props: cfg, data); without one the core draws a plain card
//     widgets: { 'stats': StatsCard },                         a block on «Сегодня» (props: locale): the component draws its own .sheet
//     editorActions: { 'check': { label: 'Проверить', run(ctx) {...} } },   a button in the top bar of the page editor (see plugins/state.js, src/README.md)
//   })
//   window.ITK.addRoute({ path: '/reports/:id?', component: ReportPage, meta: { title: 'Отчёт' } })   a route of its own (plugins/routes.js)
//
// The server shell defines a stub `window.ITK = { plugins: [], registerPlugin }` BEFORE this bundle and keeps the plugin scripts inert in <template id="itk-plugins">: installPlugins() (called
// once from start.js) takes what the stub collected, from then on registerPlugin() works at once, and loadPluginScripts() starts the scripts, in their order. Registering is live: the maps are reactive, so a plugin that arrives after the first screen
// is drawn still shows up. A broken plugin definition is skipped, it never breaks the admin.
// What a plugin can use (window.ITK): version (the contract, semver), vue (the whole of Vue: h, ref, reactive, computed, watch, ... and everything the compiled .vue files of a plugin
// import, the same Vue as the admin, so reactivity is shared), http (the API client with the CSRF token), toast, confirm, i18n ({ t, locale, add(locale, messages): the language of the
// interface and the plugin's own texts), ui (the building blocks of the admin, listed below), addRoute.
// The types of all this: admin/types/itk.d.ts.
import * as Vue from 'vue'
import { http } from '@/api/http.js'
import { addMessages, locale, t } from '@/i18n/index.js'
import { router } from '@/router/index.js'
import { toast } from '@/components/ui/useToast.js'
import { confirm } from '@/components/ui/useConfirm.js'
import FieldShell from '@/components/form/FieldShell.vue'
import SchemaForm from '@/components/form/SchemaForm.vue'
import FieldRenderer from '@/components/form/fields/FieldRenderer.vue'
import TextField from '@/components/form/fields/TextField.vue'
import TextareaField from '@/components/form/fields/TextareaField.vue'
import HeadlineField from '@/components/form/fields/HeadlineField.vue'
import NumberField from '@/components/form/fields/NumberField.vue'
import ToggleField from '@/components/form/fields/ToggleField.vue'
import SelectField from '@/components/form/fields/SelectField.vue'
import ImageField from '@/components/form/fields/ImageField.vue'
import LinkField from '@/components/form/fields/LinkField.vue'
import { fieldEmits, fieldProps } from '@/components/form/fieldProps.js'
import SortableTree from '@/components/ui/SortableTree.vue'
import KanbanBoard from '@/components/ui/KanbanBoard.vue'
import Icon from '@/components/ui/Icon.vue'
import Modal from '@/components/ui/Modal.vue'
import Drawer from '@/components/ui/Drawer.vue'
import Popover from '@/components/ui/Popover.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { addPluginRoute } from './routes.js'
import { ITK_VERSION, registerPlugin, registeredPlugins } from './state.js'

// window.ITK.ui: the components of the admin that a plugin may draw (the contract; see src/README.md). Props and events are those of the component's own file.
const UI = {
  FieldShell, Icon, Modal, Drawer, Popover, ConfirmDialog, SortableTree, KanbanBoard, SchemaForm,
  FieldRenderer, TextField, TextareaField, HeadlineField, NumberField, ToggleField, SelectField, ImageField, LinkField,
}

/** Called once from start.js, before the app starts: takes over window.ITK and the plugins that registered before this bundle ran. */
export function installPlugins() {
  if (typeof window === 'undefined') return
  const ITK = (window.ITK = window.ITK || {})
  const queued = Array.isArray(ITK.plugins) ? ITK.plugins.splice(0) : []
  const start = registeredPlugins()
  ITK.version = ITK_VERSION
  ITK.plugins = start
  ITK.registerPlugin = registerPlugin
  ITK.addRoute = (route) => addPluginRoute(router, route)
  ITK.vue = Vue
  ITK.http = http
  ITK.toast = toast
  ITK.confirm = confirm
  ITK.i18n = { t, get locale() { return locale }, add: addMessages }
  ITK.ui = { ...Object.fromEntries(Object.entries(UI).map(([name, component]) => [name, Vue.markRaw(component)])), fieldProps, fieldEmits }
  queued.forEach(registerPlugin)
}

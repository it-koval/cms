// window.ITK: the contract between the admin and the scripts of a site's plugins (Registry::adminAssets(js: [...]) on the server).
// Version 1.1.0 (ITK.version). Described in prose in admin/src/README.md, «Плагины админки».
// The admin is not published to npm (the package is private), so the file is not in node_modules: copy it into the plugin project (for example to types/itk.d.ts) or point at it by a relative path
// to a checkout of the CMS, and let the project see it:
//
//   // jsconfig.json / tsconfig.json of the plugin project
//   { "compilerOptions": { "checkJs": true, "noEmit": true, "moduleResolution": "bundler", "module": "esnext", "target": "es2020" }, "include": ["src", "types/itk.d.ts"] }
//
// or, in one file, `/// <reference path="../types/itk.d.ts" />`. After that `ITK` (and `window.ITK`) is typed everywhere in the project, no import needed.
//
// Minor versions add things, a major one changes or removes something a plugin could rely on. `vue` is the package `vue` of the plugin project, used for types only: at run time the plugin gets the admin's Vue (ITK.vue).
import type * as VueNamespace from 'vue'
import type { Component } from 'vue'

export type ItkAction = 'view' | 'edit' | 'publish' | 'delete'

/** A declarative field of the schema (utils/fields.js on the admin side): { k, t, label, ... }; `t` is a built-in type or the name of a type a plugin registered. */
export interface ItkField {
  k: string
  t: string
  label: string
  req?: boolean
  max?: number
  help?: string
  ro?: boolean
  [more: string]: unknown
}

/** The API client: JSON in and out, the CSRF token is sent for you. An answer is the parsed JSON (null for an empty one); an error is rejected as ApiError. */
export interface ItkHttp {
  get<T = unknown>(path: string, query?: Record<string, unknown>): Promise<T>
  post<T = unknown>(path: string, body?: unknown, query?: Record<string, unknown>): Promise<T>
  put<T = unknown>(path: string, body?: unknown, query?: Record<string, unknown>): Promise<T>
  patch<T = unknown>(path: string, body?: unknown, query?: Record<string, unknown>): Promise<T>
  del<T = unknown>(path: string, query?: Record<string, unknown>): Promise<T>
  upload<T = unknown>(path: string, form: FormData): Promise<T>
}

export interface ItkToast {
  show(message: string, options?: { undo?: () => void; undoLabel?: string; timeout?: number }): number
  error(message: string, options?: { timeout?: number }): number
  dismiss(id: number): void
}

export interface ItkConfirmOptions {
  title?: string
  message?: string
  confirmLabel?: string
  cancelLabel?: string
  danger?: boolean
}

/** A text of a catalogue: a string, or the forms by the categories of Intl.PluralRules (`other` is always there). */
export type ItkMessage = string | ({ other: string } & Partial<Record<'zero' | 'one' | 'two' | 'few' | 'many', string>>)

/** The language of the interface (not the language of the content: `locale` of a context is that one). The page reloads when it changes, so nothing here needs to be reactive. */
export interface ItkI18n {
  /** The text of a key in the language of the screen, then in English, then the key itself. {name} takes params.name; a text with forms takes params.count. */
  t(key: string, params?: Record<string, unknown>): string
  /** 'en' | 'ru' | 'kk'. */
  readonly locale: string
  /** Adds the texts of the plugin for one language: `{ 'reports.title': 'Отчёты' }`, `{ 'reports.count': { one: '{count} отчёт', few: '{count} отчёта', many: '{count} отчётов', other: '{count} отчёта' } }`. */
  add(locale: string, messages: Record<string, ItkMessage>): void
}

/** What the function of `actions` gets. `run(input)` sends the action to the server (POST) and answers with its ActionResult. */
export interface ItkActionContext {
  resource: string
  action: { key: string; label: string; [more: string]: unknown }
  ids: string[]
  records: Array<Record<string, unknown>>
  locale: string
  run(input?: Record<string, unknown>): Promise<unknown>
  done(result: unknown): Promise<void>
  toast: ItkToast
  http: ItkHttp
}

/** The result of an action: what the screen does next. */
export interface ItkActionResult {
  message?: string
  reload?: boolean
  redirect?: string
  download?: unknown
}

/** What `run(ctx)` and `when(ctx)` of an editor button get. */
export interface ItkEditorContext {
  pageId: string
  locale: string
  title: string
  /** 'published' | 'draft' | 'changed' */
  status: string
  /** The key of the template of the page. */
  template: string
  readOnly: boolean
  /** A copy of the page as it is on screen (read when you ask: nothing you change in it reaches the editor). */
  readonly page: { id: string; title: string; url: string; template: string; fields: Record<string, unknown>; sections: Array<{ id: string; type: string; data: Record<string, unknown>; hidden?: boolean }>; [more: string]: unknown }
  /** Save what waits for the autosave. `run` is called after it already. */
  flush(): Promise<void>
  /** Read the draft from the server again (after your button changed the page through ITK.http). */
  reload(): Promise<void>
  toast: ItkToast
  http: ItkHttp
}

/** A button in the top bar of the page editor. A name of an icon of the admin is optional (an unknown one draws a document). */
export interface ItkEditorAction {
  label: string
  run(ctx: ItkEditorContext): void | Promise<void>
  icon?: string
  title?: string
  /** Buttons go by `order` (default 0), then in the order they were registered. */
  order?: number
  /** Return false to hide the button for this page. */
  when?(ctx: ItkEditorContext): boolean
}

export interface ItkPlugin {
  /** Types of fields: a field of the schema with t: 'color' is drawn by this component (props: ItkUi.fieldProps; a `cell(value, field)` function on the component gives the text of a list column). */
  fields?: Record<string, Component>
  /** Screens: NavItem::make('Отчёты', '/x/reports') opens #/x/reports with this component (props: path, query). */
  panels?: Record<string, Component>
  /** The client side of a server action: 'resource:action' or just 'action'. A function, or { modal: Component }. */
  actions?: Record<string, ((ctx: ItkActionContext) => ItkActionResult | void | Promise<ItkActionResult | void>) | { modal: Component }>
  /** The card on the record page of a collection (props: cfg, data). */
  previews?: Record<string, Component>
  /** Blocks under the sections of «Сегодня» (props: locale). The component draws its own `.sheet`. */
  widgets?: Record<string, Component>
  /** Buttons in the top bar of the page editor. */
  editorActions?: Record<string, ItkEditorAction>
}

export interface ItkRoute {
  /** Starts with «/»; its first part is not one of the admin's own («pages», «collections», «settings», «x»...). */
  path: string
  component: Component | (() => Promise<{ default: Component }>)
  name?: string
  /** layout: 'app' (inside the rail, default) | 'bare'; title: the tab title. */
  meta?: { layout?: 'app' | 'bare'; title?: string; [more: string]: unknown }
}

/** The building blocks of the admin a plugin may draw. Props and events are those of the component's own file in admin/src/components. */
export interface ItkUi {
  FieldShell: Component
  Icon: Component
  Modal: Component
  Drawer: Component
  Popover: Component
  /** The dialog that ITK.confirm() opens: it is on the page once already, call ITK.confirm(), do not draw another. */
  ConfirmDialog: Component
  SortableTree: Component
  KanbanBoard: Component
  SchemaForm: Component
  FieldRenderer: Component
  TextField: Component
  TextareaField: Component
  HeadlineField: Component
  NumberField: Component
  ToggleField: Component
  SelectField: Component
  ImageField: Component
  LinkField: Component
  /** The props and the events every field component takes (components/form/fieldProps.js). */
  fieldProps: Record<string, unknown>
  fieldEmits: string[]
}

export interface ItkApi {
  /** The version of this contract, semver. */
  readonly version: string
  /** The plugins registered so far. */
  readonly plugins: ItkPlugin[]
  registerPlugin(plugin: ItkPlugin): void
  /** Add a route of your own; answers a function that removes it, or null when the route was refused (the console says why). */
  addRoute(route: ItkRoute): (() => void) | null
  /** The whole of Vue the admin runs on: the same instance, so reactivity is shared, and everything the compiled .vue files import. */
  readonly vue: typeof VueNamespace
  readonly http: ItkHttp
  readonly toast: ItkToast
  confirm(options?: ItkConfirmOptions): Promise<boolean>
  /** The language of the interface and the texts of the plugin. */
  i18n: ItkI18n
  readonly ui: ItkUi
}

declare global {
  /** The contract of the admin: `ITK.registerPlugin(...)`, `ITK.version`... (the same object as window.ITK). */
  var ITK: ItkApi
  interface Window {
    /** What the server shell wrote before the admin started (admin/src/README.md). */
    ITK_ADMIN?: Record<string, unknown>
  }
}

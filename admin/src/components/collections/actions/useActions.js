// Running the actions of a collection (Action::make(...) in the resource class on the server). One composable for the list (row / bulk / top places) and the record page (form place).
//
//   const actions = useActions(() => cfg, { onDone, rowsOf })
//   actions.at('bulk')            the actions shown in that place: row | bulk | top | form (the server already left out what this person may not run)
//   actions.start(action, ids)    asks first when the action wants it (confirm text, or a modal with fields), then runs it
//   actions.dialog                the state of the modal for <ActionDialog :state="actions.dialog" :actions="actions" />
//   actions.busy                  key of the action that is running (buttons show a spinner)
//
// What the server answers (ActionResult) is carried out here: a message → a toast; reload → onDone() (the list reloads); redirect → the router (an admin address «/pages/42») or the browser (http…);
// download → the file is saved; an action that streams a file itself arrives as { file, blob, name }. A refusal (422) is shown as an error and the modal stays open.
// A plugin (window.ITK.registerPlugin({ actions })) can take over the client side of an action: see plugins/state.js — a function gets the context
// { resource, action, ids, records, locale, run(input), done(result), toast, http } and may return an ActionResult-like object;
// { modal: Component } draws the component in the dialog with the props { resource, action, ids, close(), run(input) } (run = the action on the server, then the dialog closes).
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/index.js'
import { http } from '@/api/http.js'
import { app, refreshCounters } from '@/stores/app.js'
import { pluginAction } from '@/plugins/state.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { useToast } from '@/components/ui/useToast.js'
import { defaultFor, validate } from '@/utils/fields.js'
import { t } from '@/i18n/index.js'

/** A value for a field of a modal that nothing has typed in yet: empty, not the «Новый пункт» of a new record. */
export function emptyFor(f) {
  if (f.t === 'text' || f.t === 'textarea' || f.t === 'headline') return ''
  if (f.t === 'number') return ''
  return defaultFor(f)
}

function saveBlob(blob, name) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = name || 'file'
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10000)
}
function openDownload(href) {
  const a = document.createElement('a')
  a.href = href
  a.download = ''
  document.body.appendChild(a)
  a.click()
  a.remove()
}

export function useActions(getCfg, { onDone, rowsOf } = {}) {
  const router = useRouter()
  const confirm = useConfirm()
  const toast = useToast()
  const busy = ref('')
  const dialog = reactive({ open: false, action: null, ids: [], input: {}, quiet: true, busy: false, error: '', component: null })

  const at = (place) => (getCfg().actions || []).filter((a) => (a.places || []).includes(place))

  async function carryOut(res) {
    if (!res) return
    if (res.file) { saveBlob(res.blob, res.name); return }
    if (res.message) toast.show(res.message)
    else if (!res.reload && !res.redirect && !res.download) toast.show(t('collections.act_done'))
    if (res.download) openDownload(res.download)
    if (res.redirect) {
      if (/^https?:\/\//i.test(res.redirect)) window.location.assign(res.redirect)
      else router.push(res.redirect)
    }
    if (res.reload && onDone) await onDone(res)
    refreshCounters().catch(() => { /* the rail catches up next time */ })
  }

  const run = (action, ids, input) => api.actions.run(getCfg().key, action.key, { ids, input, locale: app.locale })

  async function execute(action, ids, input = {}) {
    busy.value = action.key
    try {
      await carryOut(await run(action, ids, input))
      return true
    } catch (e) {
      toast.error(e.message || t('collections.act_failed'))
      return false
    } finally {
      busy.value = ''
    }
  }

  function openDialog(action, ids, component = null) {
    const fields = (action.modal && action.modal.fields) || []
    Object.assign(dialog, { open: true, action, ids, input: Object.fromEntries(fields.map((f) => [f.k, emptyFor(f)])), quiet: true, busy: false, error: '', component })
  }

  async function start(action, ids = []) {
    if (busy.value) return
    const cfg = getCfg()
    const mine = pluginAction(cfg.key, action.key)
    if (typeof mine === 'function') {
      busy.value = action.key
      try {
        const ctx = {
          resource: cfg.key, action, ids, records: rowsOf ? rowsOf(ids) : [], locale: app.locale,
          run: (input = {}) => run(action, ids, input), done: carryOut, toast, http,
        }
        await carryOut(await mine(ctx))
      } catch (e) {
        toast.error((e && e.message) || t('collections.act_failed'))
      } finally {
        busy.value = ''
      }
      return
    }
    if (mine && mine.modal) { openDialog(action, ids, mine.modal); return }
    if (action.modal) { openDialog(action, ids); return }
    if (action.confirm) {
      const ok = await confirm({ title: action.label, message: action.confirm, confirmLabel: action.label, danger: !!action.danger })
      if (!ok) return
    }
    await execute(action, ids)
  }

  /** «Выполнить» in the modal: required fields first, then the action; the modal closes when it went through. */
  async function submit() {
    const action = dialog.action
    if (!action || dialog.busy) return
    const fields = (action.modal && action.modal.fields) || []
    if (validate(fields, dialog.input).length) { dialog.quiet = false; return }
    dialog.busy = true
    dialog.error = ''
    busy.value = action.key
    try {
      const res = await run(action, dialog.ids, dialog.input)
      dialog.open = false
      await carryOut(res)
    } catch (e) {
      dialog.error = e.message || t('collections.act_failed')
    } finally {
      dialog.busy = false
      busy.value = ''
    }
  }
  /** For the modal component of a plugin: run the action with whatever input it collected; the modal closes when it went through. */
  async function runFromDialog(input = {}) {
    const action = dialog.action
    if (!action) return null
    dialog.busy = true
    try {
      const res = await run(action, dialog.ids, input)
      dialog.open = false
      await carryOut(res)
      return res
    } finally {
      dialog.busy = false
    }
  }
  const close = () => { if (!dialog.busy) dialog.open = false }

  return { at, start, submit, close, runFromDialog, resource: () => getCfg().key, dialog, busy, carryOut }
}

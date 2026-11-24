// Toasts with an optional «отменить» action.
//   const toast = useToast()
//   toast.show('Сохранено')
//   toast.show('Элемент удалён', { undo: () => restore(), undoLabel: 'вернуть' })
//   toast.error('Не получилось')
//   toast.show('...', { owner: 'editor', undo }); toast.dismissOwner('editor')   a toast whose action only works on one screen goes away with it
// <ToastHost /> (mounted once in App.vue) renders them: black pill, cyan-light action link.
import { reactive } from 'vue'
import { t } from '@/i18n/index.js'

const state = reactive({ items: [] })
let seq = 0

function dismiss(id) {
  const i = state.items.findIndex((item) => item.id === id)
  if (i >= 0) state.items.splice(i, 1)
}

function show(message, opts = {}) {
  const item = { id: ++seq, message, err: !!opts.err, undo: opts.undo || null, undoLabel: opts.undoLabel || t('ui.toast_undo'), owner: opts.owner || '' }
  while (state.items.length >= 3) state.items.shift()
  state.items.push(item)
  setTimeout(() => dismiss(item.id), opts.timeout || (item.undo ? 7000 : 3800))
  return item.id
}

const api = {
  show,
  error: (message, opts = {}) => show(message, { ...opts, err: true, timeout: opts.timeout || 6000 }),
  dismiss,
  dismissOwner: (owner) => { state.items = state.items.filter((item) => item.owner !== owner) },
}

export const toastState = state
export const useToast = () => api
export const toast = api

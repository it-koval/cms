// Promise-based confirmation.
//   const confirm = useConfirm()
//   if (await confirm({ title: 'Удалить секцию?', message: '...', confirmLabel: 'Удалить', danger: true })) { ... }
// <ConfirmDialog /> (mounted once in App.vue) renders it.
import { reactive } from 'vue'
import { t } from '@/i18n/index.js'

export const confirmState = reactive({ open: false, title: '', message: '', confirmLabel: t('ui.yes'), cancelLabel: t('ui.cancel'), danger: false, resolve: null })

export function confirm(opts = {}) {
  return new Promise((resolve) => {
    if (confirmState.resolve) confirmState.resolve(false)
    Object.assign(confirmState, { open: true, title: '', message: '', confirmLabel: t('ui.yes'), cancelLabel: t('ui.cancel'), danger: false }, opts, { resolve })
  })
}
export function settle(value) {
  const r = confirmState.resolve
  confirmState.open = false
  confirmState.resolve = null
  if (r) r(value)
}
export const useConfirm = () => confirm

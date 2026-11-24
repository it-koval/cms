import { onBeforeUnmount, toValue, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRouter } from 'vue-router'
import { useLocaleGuard } from '@/stores/app.js'
import { useConfirm } from '@/components/ui/useConfirm.js'
import { t } from '@/i18n/index.js'

// Shared by settings panels whose local drafts disappear when their route or language changes.
export function useUnsavedChanges(dirty, { saving = false, message } = {}) {
  const confirm = useConfirm()
  let pending
  let localeApproved = false
  const router = useRouter()
  const removeAfter = router.afterEach(() => { localeApproved = false })
  onBeforeUnmount(removeAfter)
  async function mayLeave(languageSwitch = false) {
    if (toValue(saving)) await new Promise((resolve) => {
      const stop = watch(() => toValue(saving), (busy) => { if (!busy) { stop(); resolve() } })
    })
    if (!toValue(dirty)) return true
    pending ??= confirm({
      title: t(languageSwitch ? 'settings.switch_title' : 'settings.leave_title'),
      message: toValue(message) || t(languageSwitch ? 'settings.unsaved_switch_message' : 'settings.unsaved_leave_message'),
      confirmLabel: t(languageSwitch ? 'settings.switch_confirm' : 'settings.leave_confirm'), cancelLabel: t('settings.stay'), danger: true,
    }).finally(() => { pending = null })
    return pending
  }
  onBeforeRouteLeave(() => mayLeave())
  // The global locale guard runs before this component's update guard. Reuse its
  // answer only for that navigation; afterEach also clears it on cancellation.
  onBeforeRouteUpdate(() => localeApproved || mayLeave())
  useLocaleGuard(async () => {
    localeApproved = await mayLeave(true)
    return localeApproved
  })
  const onUnload = (event) => {
    if (!toValue(dirty)) return
    event.preventDefault()
    event.returnValue = ''
  }
  if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', onUnload)
    onBeforeUnmount(() => window.removeEventListener('beforeunload', onUnload))
  }
  return mayLeave
}

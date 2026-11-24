// @vitest-environment jsdom
import { createApp, h, nextTick, ref } from 'vue'
import { expect, it, vi } from 'vitest'
import { useOverlay } from './focus.js'

it('sends Escape only to the confirmation above a drawer', async () => {
  const drawerOpen = ref(false)
  const confirmOpen = ref(false)
  const dismissDrawer = vi.fn(() => { drawerOpen.value = false })
  const dismissConfirm = vi.fn(() => { confirmOpen.value = false })
  const app = createApp({
    setup() {
      const drawer = ref(null)
      const confirm = ref(null)
      useOverlay(drawer, drawerOpen, { onEsc: dismissDrawer })
      useOverlay(confirm, confirmOpen, { onEsc: dismissConfirm, top: true })
      return () => h('div', [h('div', { ref: drawer }), h('div', { ref: confirm })])
    },
  })
  app.mount(document.createElement('div'))
  try {
    drawerOpen.value = true
    await nextTick()
    confirmOpen.value = true
    await nextTick()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(dismissConfirm).toHaveBeenCalledTimes(1)
    expect(dismissDrawer).not.toHaveBeenCalled()
    await nextTick()
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(dismissDrawer).toHaveBeenCalledTimes(1)
  } finally {
    app.unmount()
  }
})

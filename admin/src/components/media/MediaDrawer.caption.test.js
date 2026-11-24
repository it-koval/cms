// @vitest-environment jsdom
import { createApp, h, nextTick, reactive, ssrContextKey } from 'vue'
import { parse } from '@vue/compiler-sfc'
import { afterEach, expect, it, vi } from 'vitest'
import MediaDrawer from './MediaDrawer.vue'
import source from './MediaDrawer.vue?raw'
import Drawer from '@/components/ui/Drawer.vue'
import drawerSource from '@/components/ui/Drawer.vue?raw'
import { clientComponent } from '@/test/clientComponent.js'
vi.mock('vue-router', () => ({ onBeforeRouteLeave: vi.fn() }))
vi.mock('@/stores/app.js', () => ({ can: () => false }))
vi.mock('./ImageEditorDialog.vue', () => ({ default: { render: () => null } }))
clientComponent(MediaDrawer, source); clientComponent(Drawer, drawerSource)
let mounted
const tick = async () => { await nextTick(); await nextTick() }
afterEach(() => { mounted?.unmount(); document.body.innerHTML = ''; document.head.querySelectorAll('[data-drawer-caption-test]').forEach((el) => el.remove()) })
it.each([375, 320])('uses word wrapping and an emergency break for the full drawer filename at %ipx', async (width) => {
  const style = document.createElement('style'); style.dataset.drawerCaptionTest = ''; style.textContent = parse(source).descriptor.styles.map((sheet) => sheet.content).join('\n'); document.head.append(style)
  const host = document.createElement('div'); host.style.width = width + 'px'; document.body.append(host)
  const item = reactive({ id: 'pdf', type: 'pdf', name: '', kb: 1 })
  mounted = createApp({ render: () => h(MediaDrawer, { item, usage: [] }) }); mounted.component('router-link', { render() { return h('a', this.$slots.default?.()) } }); mounted.provide(ssrContextKey, { modules: new Set() }); mounted.mount(host)
  for (const name of ['A readable filename with several words.pdf', 'x'.repeat(180) + '.pdf']) {
    item.name = name; await tick()
    const label = document.querySelector('.md-name')
    expect(label.textContent).toBe(name)
    expect(getComputedStyle(label).wordBreak).not.toBe('break-all')
    expect(getComputedStyle(label).overflowWrap).toBe('anywhere')
  }
})

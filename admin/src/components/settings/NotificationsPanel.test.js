import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'
import NotificationsPanel from './NotificationsPanel.vue'
import { t } from '@/i18n/index.js'

let botsAccess = false
vi.mock('@/stores/app.js', () => ({ can: (area) => area === 'set:bots' && botsAccess }))
vi.mock('./ChatRoutesTable.vue', () => ({ default: { render: () => null } }))

async function render() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/settings/:tab?', name: 'settings', component: { render: () => null } }] })
  const app = createSSRApp({ render: () => h(NotificationsPanel, { data: { tgReady: false, maxReady: false }, saved: {} }) })
  app.use(router)
  await router.push('/settings/notifications')
  await router.isReady()
  return renderToString(app)
}

describe('notification setup guidance', () => {
  it('asks an administrator when bot settings are not accessible', async () => {
    botsAccess = false
    const html = await render()
    expect(html).toContain(t('settings.notif_bots_ask'))
    expect(html).not.toContain(t('settings.notif_bots_setup'))
    expect(html).not.toContain('href="/settings/bots"')
  })

  it('offers bot setup only to a role that may view bot settings', async () => {
    botsAccess = true
    const html = await render()
    expect(html).toContain(t('settings.notif_bots_setup'))
    expect(html).toContain('href="/settings/bots"')
    expect(html).not.toContain(t('settings.notif_bots_ask'))
  })
})

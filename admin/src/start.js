// What main.js imports once the language of the interface is loaded: the app itself. Everything below may call t() at the top of its modules.
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router/index.js'
import { initApp } from './stores/app.js'
import { installPlugins } from './plugins/registry.js'
import { loadPluginScripts } from './plugins/scripts.js'
import { boot } from '@/api/http.js'
import TwoFactorEnrol from './views/TwoFactorEnrol.vue'

export async function start() {
  // `npm run dev:mock` (VITE_MOCK=1): the API is answered by src/mock/ in the browser, no PHP server needed. Not in a build without the variable: the branch is cut out.
  if (import.meta.env.VITE_MOCK === '1') await (await import('./mock/install.js')).installMock()

  installPlugins() // window.ITK: plugins of the site register here (plugins/registry.js)
  const plugins = loadPluginScripts() // their scripts start now that ITK is ready; a route of a plugin must exist before the first screen is drawn
  // a role that demands the second sign-in factor: until it is on, the server answers only /admin/api/2fa, so the admin itself does not start
  const twoFactor = (boot().user && boot().user.twoFactor) || {}
  if (twoFactor.enrol) {
    createApp(TwoFactorEnrol).mount('#app')
  } else {
    Promise.allSettled([initApp(), plugins]).then(() => {
      createApp(App).use(router).mount('#app')
    })
  }
}

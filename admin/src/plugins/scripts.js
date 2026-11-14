// The scripts of a site's plugins (Registry::adminAssets(js: [...]) on the server). The shell keeps them inert in <template id="itk-plugins">: the admin loads its language and its code asynchronously,
// and a plugin reads ITK.vue on its first line. start.js calls this once window.ITK is ready.

/** The longest the first screen waits for a script that does not answer (ms). One that arrives later still registers: the maps of plugins are live. */
const WAIT = 8000

/** Starts the scripts in their order. Resolves when they ran or failed: a broken plugin never keeps the admin from starting. */
export function loadPluginScripts(page = document, wait = WAIT) {
  const held = page.getElementById('itk-plugins')
  if (!held) return Promise.resolve()
  const scripts = [...held.content.querySelectorAll('script[src]')].map((script) => new Promise((resolve) => {
    const started = page.createElement('script')
    started.type = 'module'
    started.async = false // dynamic scripts run as they arrive unless told to keep their order
    started.src = script.getAttribute('src')
    started.onload = resolve
    started.onerror = resolve
    page.head.appendChild(started)
  }))
  return Promise.race([Promise.all(scripts), new Promise((resolve) => { setTimeout(resolve, wait) })])
}

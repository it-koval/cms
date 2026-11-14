import { describe, expect, it } from 'vitest'
import { loadPluginScripts } from './scripts.js'

const page = (urls) => {
  const started = []
  return {
    started,
    head: { appendChild: (script) => started.push(script) },
    createElement: () => ({}),
    getElementById: () => (urls ? { content: { querySelectorAll: () => urls.map((url) => ({ getAttribute: () => url })) } } : null),
  }
}

describe('the scripts of plugins', () => {
  it('nothing to do on a page without plugins', async () => {
    await expect(loadPluginScripts(page(null))).resolves.toBeUndefined()
  })

  it('starts them as ordered modules and waits for each, also for one that failed', async () => {
    const p = page(['/a.js', '/b.js'])
    let done = false
    const loading = loadPluginScripts(p).then(() => { done = true })
    expect(p.started.map((s) => [s.src, s.type, s.async])).toEqual([['/a.js', 'module', false], ['/b.js', 'module', false]])
    p.started[0].onload()
    await Promise.resolve()
    expect(done).toBe(false)
    p.started[1].onerror()
    await loading
    expect(done).toBe(true)
  })

  it('a script that does not answer does not hold the admin for ever', async () => {
    await expect(loadPluginScripts(page(['/hangs.js']), 1)).resolves.toBeUndefined()
  })
})

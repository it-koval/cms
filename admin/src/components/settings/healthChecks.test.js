import { describe, expect, it } from 'vitest'
import { checkChip } from './healthChecks.js'

const t = (key) => `<${key}>`

describe('health check chips', () => {
  it('shows a module that is not connected as a yellow «not connected», not as «working»', () => {
    expect(checkChip('off', t)).toEqual(['warn', '<settings.health_check_off>'])
    expect(checkChip('ok', t)).toEqual(['pub', '<settings.health_check_ok>'])
  })

  it('keeps warnings and errors as they were', () => {
    expect(checkChip('warn', t)).toEqual(['warn', '<settings.health_check_warn>'])
    expect(checkChip('fail', t)).toEqual(['fail', '<settings.health_check_fail>'])
  })

  it('does not break on a status it does not know', () => {
    expect(checkChip('paused', t)).toEqual(['', 'paused'])
  })
})

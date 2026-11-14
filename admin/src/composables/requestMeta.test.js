import { describe, expect, it } from 'vitest'
import { resendOutcome } from './requestMeta.js'

const both = {
  delivery: 'failed',
  deliveryError: 'MAX: refused',
  channels: [
    { channel: 'telegram', state: 'sent', error: null },
    { channel: 'max', state: 'failed', error: 'MAX: refused' },
  ],
}

describe('resendOutcome', () => {
  it('judges a per-channel resend by that channel, not by the aggregate', () => {
    expect(resendOutcome(both, 'telegram')).toEqual({ state: 'sent', error: '' })
    expect(resendOutcome(both, 'max')).toEqual({ state: 'failed', error: 'MAX: refused' })
  })

  it('reports a channel that did not go out as pending', () => {
    const r = { delivery: 'failed', channels: [{ channel: 'max', state: 'pending', error: null }] }
    expect(resendOutcome(r, 'max').state).toBe('pending')
  })

  it('uses the aggregate for a whole-request resend', () => {
    expect(resendOutcome(both)).toEqual({ state: 'failed', error: 'MAX: refused' })
    expect(resendOutcome({ delivery: 'sent' }).state).toBe('sent')
    expect(resendOutcome({ delivery: 'pending' }).state).toBe('pending')
  })
})

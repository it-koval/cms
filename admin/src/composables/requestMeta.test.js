import { describe, expect, it } from 'vitest'
import { resendOutcome, sendableOf } from './requestMeta.js'

const both = {
  delivery: 'failed',
  deliveryError: 'MAX: refused',
  channels: [
    { channel: 'telegram', state: 'sent', error: null },
    { channel: 'max', state: 'failed', error: 'MAX: refused' },
  ],
}

describe('resendOutcome', () => {
  it('reports successful dispatch separately from an earlier failed delivery', () => {
    expect(resendOutcome({ ...both, queuedChannels: ['max'] }, 'max')).toEqual({ state: 'queued', error: '' })
    expect(resendOutcome({ ...both, queuedChannels: ['max'] }, 'telegram')).toEqual({ state: 'sent', error: '' })
    expect(resendOutcome({ ...both, queuedChannels: ['max'] }).state).toBe('queued')
    expect(resendOutcome({ ...both, queuedChannels: [] }, 'max').state).toBe('failed')
  })

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

describe('sendableOf', () => {
  it('lists the messengers the server says can take the request', () => {
    expect(sendableOf({ sendable: ['max'] })).toEqual(['max'])
    expect(sendableOf({ sendable: ['telegram', 'max'], channels: [] })).toEqual(['telegram', 'max'])
  })

  it('is empty for a server that does not send it', () => {
    expect(sendableOf({})).toEqual([])
    expect(sendableOf({ sendable: null })).toEqual([])
  })
})

import { describe, expect, it } from 'vitest'
import { bodyKind, failReasonText, isConnected, isExpired, num, periodText, problemGroups, renewsSoon } from './yandexWebmaster.js'

const status = (over) => ({ state: 'ready', canManage: true, tokenExpiresSoon: false, tokenExpiresAt: null, ...over })

describe('what the card draws for a state', () => {
  it('not connected: a form for who may manage, a request to the administrator for the rest', () => {
    expect(bodyKind(status({ state: 'not_connected' }))).toBe('connect')
    expect(bodyKind(status({ state: 'not_connected', canManage: false }))).toBe('ask_admin')
  })

  it('a rejected token asks for a new one', () => {
    expect(bodyKind(status({ state: 'token_invalid' }))).toBe('renew')
    expect(bodyKind(status({ state: 'token_invalid', canManage: false }))).toBe('ask_admin_renew')
  })

  it.each(['no_address', 'no_http_client', 'verifying', 'verification_failed', 'ready'])('%s has its own body', (state) => {
    expect(bodyKind(status({ state }))).toBe(state)
  })

  it('the refresh line is for connected states only', () => {
    const connected = ['token_invalid', 'verifying', 'verification_failed', 'ready'].filter((state) => isConnected(status({ state })))
    expect(connected).toHaveLength(4)
    for (const state of ['not_connected', 'no_address', 'no_http_client']) expect(isConnected(status({ state }))).toBe(false)
  })
})

describe('the token that runs out', () => {
  it('warns on a working connection, not on one that is already rejected or not made', () => {
    expect(renewsSoon(status({ tokenExpiresSoon: true }))).toBe(true)
    expect(renewsSoon(status({ state: 'token_invalid', tokenExpiresSoon: true }))).toBe(false)
    expect(renewsSoon(status({ state: 'not_connected', tokenExpiresSoon: true }))).toBe(false)
    expect(renewsSoon(status())).toBe(false)
  })

  it('tells a past date from a coming one', () => {
    const now = Date.parse('2026-11-06T00:00:00Z')
    expect(isExpired(status({ tokenExpiresAt: '2026-11-01T00:00:00+00:00' }), now)).toBe(true)
    expect(isExpired(status({ tokenExpiresAt: '2026-11-10T00:00:00+00:00' }), now)).toBe(false)
    expect(isExpired(status(), now)).toBe(false)
  })
})

describe('the numbers', () => {
  it('lists only the problem groups with something in them, the red ones first', () => {
    const groups = problemGroups({ FATAL: 0, CRITICAL: 1, POSSIBLE_PROBLEM: 3, RECOMMENDATION: 0 })
    expect(groups.map((g) => [g.code, g.count, g.tone])).toEqual([['CRITICAL', 1, 'fail'], ['POSSIBLE_PROBLEM', 3, '']])
    expect(problemGroups({ FATAL: 0, CRITICAL: 0, POSSIBLE_PROBLEM: 0, RECOMMENDATION: 0 })).toEqual([])
    expect(problemGroups(null)).toEqual([])
  })

  it('writes an undefined indicator as a dash and a big number with a space', () => {
    expect(num(null)).toBe('—')
    expect(num(0)).toBe('0')
    expect(num(11000)).toMatch(/^11\s000$/)
  })

  it('names the week by its days', () => {
    expect(periodText({ from: '2026-10-30', to: '2026-11-05' })).toMatch(/^30 окт\.? – 5 нояб\.?$/)
  })
})

describe('why Yandex did not verify the site', () => {
  it('says the known reason in words and a code it does not know as a code', () => {
    expect(failReasonText('META_TAG_NOT_FOUND')).toMatch(/мета-тег/)
    expect(failReasonText('DNS_RECORD_NOT_FOUND')).toContain('DNS_RECORD_NOT_FOUND')
    expect(failReasonText(null)).toMatch(/не назвал причину/)
  })
})

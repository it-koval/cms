import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { initI18n } from '@/i18n/index.js'
import { dayOf, fmtD, fmtDT, fmtPageUpdated, fmtUpdated, initialsOf, MIGRATED_NOTE, nf, siteOrigin } from './format.js'

// "now" is 2026-11-10 12:00 local time; dates are built from local parts so the tests do not depend on the time zone
const at = (y, m, d, h = 0, mi = 0, sec = 0) => new Date(y, m - 1, d, h, mi, sec)
beforeEach(() => { vi.useFakeTimers(); vi.setSystemTime(at(2026, 11, 10, 12, 0)) })
afterEach(async () => { vi.useRealTimers(); await initI18n('ru') })

describe('dates', () => {
  it('dayOf gives the local date', () => {
    expect(dayOf(at(2026, 1, 5, 23, 59))).toBe('2026-01-05')
  })

  it('fmtDT says today, yesterday or the day', () => {
    expect(fmtDT(at(2026, 11, 10, 9, 5))).toBe('сегодня, 09:05')
    expect(fmtDT(at(2026, 11, 9, 21, 3))).toBe('вчера, 21:03')
    expect(fmtDT(at(2026, 10, 26, 19, 40))).toBe('26 октября, 19:40')
  })

  it('fmtDT reads the clock of English without a leading zero, the others keep it', async () => {
    await initI18n('en')
    expect(fmtDT(at(2026, 11, 10, 9, 5))).toBe('today, 9:05 AM')
    await initI18n('kk')
    expect(fmtDT(at(2026, 11, 10, 9, 5))).toMatch(/09:05/)
  })

  it('fmtD is dd.mm.yyyy', () => {
    expect(fmtD(at(2026, 10, 28))).toBe('28.10.2026')
  })

  it('fmtUpdated is relative for the last hour and then by the day', () => {
    expect(fmtUpdated('')).toBe('')
    expect(fmtUpdated(at(2026, 11, 10, 11, 59, 40))).toBe('только что')
    expect(fmtUpdated(at(2026, 11, 10, 11, 48))).toBe('12 минут назад')
    expect(fmtUpdated(at(2026, 11, 10, 11, 1))).toBe('59 минут назад')
    expect(fmtUpdated(at(2026, 11, 10, 8, 14))).toBe('сегодня, 08:14')
    expect(fmtUpdated(at(2026, 11, 9, 22, 0))).toBe('вчера')
    expect(fmtUpdated(at(2026, 10, 7))).toMatch(/^7 окт/)
    expect(fmtUpdated(at(2025, 10, 7))).toMatch(/^7 окт.* 2025/)
  })
})

describe('fmtPageUpdated', () => {
  it('says a moved page was moved from the old site, whatever the old site was', () => {
    expect(MIGRATED_NOTE).toBe('перенесена со старого сайта')
    expect(fmtPageUpdated({ migrated: true, updatedAt: at(2026, 11, 10, 8, 14) })).toBe(MIGRATED_NOTE)
    expect(MIGRATED_NOTE).not.toMatch(/modx/i)
  })

  it('shows the time of the last change once the page was edited', () => {
    expect(fmtPageUpdated({ migrated: false, updatedAt: at(2026, 11, 10, 8, 14) })).toBe('сегодня, 08:14')
  })
})

describe('numbers and names', () => {
  it('nf groups thousands with a non-breaking space', () => {
    expect(nf(11000)).toBe('11\u00a0000')
    expect(nf('')).toBe('')
    expect(nf(null)).toBe('')
    expect(nf(0)).toBe('0')
  })

  it('initialsOf takes two letters of the first two words', () => {
    expect(initialsOf('Анна Сергеева')).toBe('АС')
    expect(initialsOf('мария')).toBe('М')
    expect(initialsOf('  ')).toBe('?')
  })

  it('siteOrigin adds https and strips trailing slashes', () => {
    expect(siteOrigin('example.com')).toBe('https://example.com')
    expect(siteOrigin('http://localhost:8091/')).toBe('http://localhost:8091')
  })
})
